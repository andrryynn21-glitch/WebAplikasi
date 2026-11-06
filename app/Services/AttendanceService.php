<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\AttendanceCourse;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\AttendanceStudent;
use App\Models\ClassRoom;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AttendanceService
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function classFor(User $actor, bool $lock = false): ClassRoom
    {
        $classId = $actor->activeClassId();
        if ($classId === null) {
            throw new AuthorizationException;
        }

        $class = ClassRoom::query()->with('academicTerm')->when($lock, fn (Builder $q) => $q->lockForUpdate())->findOrFail($classId);
        $actor = User::query()->when($lock, fn (Builder $q) => $q->lockForUpdate())->findOrFail($actor->id);
        if ($lock) {
            // Current locking reads must reject access revoked after the transaction snapshot began.
            $membership = $actor->activeMembership()->where('class_id', $class->id)->lockForUpdate()->first();
            $assignment = $class->administratorAssignments()->where('user_id', $actor->id)
                ->where('starts_at', '<=', now())->whereNull('ends_at')->lockForUpdate()->first();
            if ($membership === null || $assignment === null) {
                throw new AuthorizationException;
            }
        }
        Gate::forUser($actor)->authorize('manageAttendance', $class);

        return $class;
    }

    public function classData(ClassRoom $class): array
    {
        return [
            ...$class->only(['id', 'code', 'name', 'semester']),
            'academic_term' => [
                ...$class->academicTerm->only(['id', 'code', 'name']),
                'starts_on' => $class->academicTerm->starts_on->toDateString(),
                'ends_on' => $class->academicTerm->ends_on->toDateString(),
            ],
        ];
    }

    public function roster(ClassRoom $class, bool $lock = false): Collection
    {
        return AttendanceStudent::query()->where('class_id', $class->id)->where('is_active', true)
            ->when($lock, fn (Builder $query) => $query->lockForUpdate())->orderBy('name')->orderBy('id')->get()
            ->map(fn ($student) => ['student_id' => $student->id, 'user_id' => $student->user_id, 'name' => $student->name, 'nim' => $student->nim]);
    }

    public function students(ClassRoom $class): Collection
    {
        return AttendanceStudent::query()->where('class_id', $class->id)->orderByDesc('is_active')->orderBy('name')->orderBy('id')->get()
            ->map(fn ($student) => $student->only(['id', 'user_id', 'name', 'nim', 'is_active']));
    }

    public function addStudents(User $actor, array $rows): array
    {
        return DB::transaction(function () use ($actor, $rows): array {
            $class = $this->classFor($actor, true);
            // Current reads include writes committed while this transaction waited for the class lock.
            $activeCount = AttendanceStudent::query()->where('class_id', $class->id)->where('is_active', true)->lockForUpdate()->get(['id'])->count();
            if ($activeCount + count($rows) > 1000) {
                throw ValidationException::withMessages(['students' => 'Maksimal 1.000 mahasiswa aktif dalam daftar absensi kelas.']);
            }
            $created = [];
            foreach ($rows as $index => $row) {
                $nim = trim($row['nim'] ?? '');
                $nim = $nim === '' ? null : $nim;
                if ($nim !== null && AttendanceStudent::query()->where('class_id', $class->id)->where('nim', $nim)->lockForUpdate()->first(['id']) !== null) {
                    throw ValidationException::withMessages(["students.{$index}.nim" => 'NIM sudah ada dalam daftar kelas, termasuk arsip. Edit atau aktifkan kembali mahasiswa tersebut.']);
                }
                $student = AttendanceStudent::create(['class_id' => $class->id, 'name' => trim($row['name']), 'nim' => $nim, 'is_active' => true]);
                $created[] = ['id' => $student->id, 'user_id' => null, 'name' => $student->name, 'nim' => $student->nim, 'is_active' => true];
            }
            $this->audit->record(AuditAction::AttendanceRosterUpdated, $actor, $class, $class->id);

            return $created;
        }, 3);
    }

    public function updateStudent(User $actor, int $id, array $data): array
    {
        return DB::transaction(function () use ($actor, $id, $data): array {
            $class = $this->classFor($actor, true);
            $student = AttendanceStudent::query()->where('class_id', $class->id)->lockForUpdate()->findOrFail($id);
            $nim = array_key_exists('nim', $data) ? trim($data['nim'] ?? '') : ($student->nim ?? '');
            $nim = $nim === '' ? null : $nim;
            if ($nim !== null && $nim !== $student->nim && AttendanceStudent::query()->where('class_id', $class->id)->where('nim', $nim)->whereKeyNot($id)->lockForUpdate()->first(['id']) !== null) {
                throw ValidationException::withMessages(['nim' => 'NIM sudah digunakan mahasiswa lain dalam daftar kelas.']);
            }
            if (! $student->is_active && ($data['is_active'] ?? false)
                && AttendanceStudent::query()->where('class_id', $class->id)->where('is_active', true)->lockForUpdate()->get(['id'])->count() >= 1000) {
                throw ValidationException::withMessages(['is_active' => 'Maksimal 1.000 mahasiswa aktif dalam daftar absensi kelas.']);
            }
            $student->fill(['name' => trim($data['name']), 'nim' => $nim, 'is_active' => $data['is_active'] ?? $student->is_active])->save();
            $this->audit->record(AuditAction::AttendanceRosterUpdated, $actor, $student, $class->id);

            return $student->only(['id', 'user_id', 'name', 'nim', 'is_active']);
        }, 3);
    }

    public function saveCourse(User $actor, array $data, ?int $id = null): AttendanceCourse
    {
        return DB::transaction(function () use ($actor, $data, $id) {
            $class = $this->classFor($actor, true);
            $course = $id === null ? new AttendanceCourse(['class_id' => $class->id])
                : AttendanceCourse::query()->where('class_id', $class->id)->lockForUpdate()->findOrFail($id);
            $code = trim($data['code'] ?? '');
            $data['code'] = $code !== '' ? $code : ($course->code ?? 'MK-'.Str::upper(Str::random(8)));
            $data['semester'] = $data['semester'] ?? $course->semester ?? $class->semester;
            if (AttendanceCourse::query()->where('class_id', $class->id)->where('code', $data['code'])
                ->when($id !== null, fn (Builder $q) => $q->whereKeyNot($id))->lockForUpdate()->first(['id']) !== null) {
                throw ValidationException::withMessages(['code' => 'Kode mata kuliah sudah digunakan di kelas ini.']);
            }
            $course->fill($data)->save();
            $this->audit->record(AuditAction::AttendanceCourseSaved, $actor, $course, $class->id, $course->only(['code', 'name', 'is_active']));

            return $course->refresh()->loadCount('sessions')->loadMax('sessions', 'meeting_number');
        }, 3);
    }

    public function sessions(ClassRoom $class, array $filters = []): Builder
    {
        $query = AttendanceSession::query()->whereHas('course', fn (Builder $q) => $q->where('class_id', $class->id));
        if (! empty($filters['course_id'])) {
            AttendanceCourse::query()->where('class_id', $class->id)->findOrFail($filters['course_id']);
            $query->where('course_id', $filters['course_id']);
        }
        foreach (['date_from' => '>=', 'date_to' => '<='] as $field => $operator) {
            if (! empty($filters[$field])) {
                $query->where('held_on', $operator, $filters[$field]);
            }
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query;
    }

    public function withDetails(Builder $query): Builder
    {
        $counts = ['records'];
        foreach (AttendanceRecord::STATUSES as $status) {
            $counts['records as '.$status.'_count'] = fn (Builder $q) => $q->where('status', $status);
        }
        $counts['records as unmarked_count'] = fn (Builder $q) => $q->whereNull('status');

        return $query->with(['creator:id,name', 'updater:id,name'])->withCount($counts);
    }

    public function detail(ClassRoom $class, int $id): array
    {
        $session = $this->withDetails($this->sessions($class))->with(['records' => fn ($q) => $q->orderBy('name')->orderBy('student_id'),
            'events' => fn ($q) => $q->orderBy('id'), 'events.actor:id,name'])->findOrFail($id);

        return $this->sessionData($session) + [
            'records' => $session->records->map(fn (AttendanceRecord $r) => $r->only(['student_id', 'user_id', 'name', 'nim', 'status', 'note'])),
            'history' => $session->events->map(fn ($event) => [
                'action' => $event->action, 'actor' => $event->actor?->only(['id', 'name']),
                'created_at' => $event->created_at->toIso8601String(), 'reason' => $event->reason,
            ]),
        ];
    }

    public function sessionData(AttendanceSession $session): array
    {
        $counts = ['total' => (int) $session->records_count, 'unmarked' => (int) $session->unmarked_count];
        foreach (AttendanceRecord::STATUSES as $status) {
            $counts[$status] = (int) $session->{$status.'_count'};
        }

        return [
            ...$session->only(['id', 'course_id', 'meeting_number', 'semester', 'topic', 'notes', 'status', 'version', 'lecturer_name']),
            'course' => ['id' => $session->course_id, 'code' => $session->course_code, 'name' => $session->course_name, 'lecturer_name' => $session->lecturer_name, 'semester' => $session->semester],
            'held_on' => $session->held_on->toDateString(),
            'day_name' => $session->held_on->locale('id')->isoFormat('dddd'),
            'starts_at' => $session->starts_at ? substr($session->starts_at, 0, 5) : null,
            'ends_at' => $session->ends_at ? substr($session->ends_at, 0, 5) : null,
            'counts' => $counts,
            'created_by' => $session->creator?->only(['id', 'name']),
            'updated_by' => $session->updater?->only(['id', 'name']),
            'created_at' => $session->created_at->toIso8601String(),
            'updated_at' => $session->updated_at->toIso8601String(),
            'finalized_at' => $session->finalized_at?->toIso8601String(),
        ];
    }

    public function create(User $actor, array $data): array
    {
        return DB::transaction(function () use ($actor, $data) {
            $class = $this->classFor($actor, true);
            $roster = $this->roster($class, true);
            if ($roster->isEmpty() || $roster->count() > 1000) {
                throw ValidationException::withMessages(['roster' => 'Tambahkan 1 sampai 1.000 mahasiswa ke daftar absensi. Mereka tidak perlu memiliki akun.']);
            }
            $course = isset($data['course_id'])
                ? AttendanceCourse::query()->where('class_id', $class->id)->lockForUpdate()->findOrFail($data['course_id'])
                : $this->saveCourse($actor, $data['course']);
            if (! $course->is_active) {
                throw ValidationException::withMessages(['course_id' => 'Aktifkan kembali mata kuliah sebelum membuat pertemuan.']);
            }
            $data['course_id'] = $course->id;
            $data['meeting_number'] ??= (AttendanceSession::query()->where('course_id', $course->id)
                ->orderByDesc('meeting_number')->lockForUpdate()->first(['meeting_number'])?->meeting_number ?? 0) + 1;
            $this->validateMeeting($class, $course->id, $data);
            unset($data['course']);
            $session = new AttendanceSession([
                ...$data, 'course_code' => $course->code, 'course_name' => $course->name, 'lecturer_name' => $course->lecturer_name,
                'semester' => $course->semester,
            ]);
            $session->forceFill(['created_by' => $actor->id, 'updated_by' => $actor->id, 'status' => 'draft', 'version' => 1])->save();
            $session->records()->createMany($roster->all());
            $this->recordEvent($session, $actor, $class, AuditAction::AttendanceCreated);

            return $this->detail($class, $session->id);
        }, 3);
    }

    public function save(User $actor, int $id, array $data): array
    {
        return DB::transaction(function () use ($actor, $id, $data) {
            $class = $this->classFor($actor, true);
            $session = $this->sessions($class)->lockForUpdate()->findOrFail($id);
            $this->requireVersion($session, $data['version']);
            if ($session->status !== 'draft') {
                $this->conflict('Absensi sudah final. Buka koreksi dengan alasan sebelum mengubahnya.');
            }
            $this->validateMeeting($class, $session->course_id, $data, $id);
            $records = $session->records()->lockForUpdate()->get()->keyBy('student_id');
            $ids = collect($data['records'])->pluck('student_id')->map(fn ($id) => (int) $id);
            if ($ids->count() !== $records->count() || $ids->unique()->count() !== $ids->count()
                || $ids->diff($records->keys())->isNotEmpty()) {
                throw ValidationException::withMessages(['records' => 'Daftar mahasiswa harus sama persis dengan daftar saat pertemuan dibuat. Muat ulang jika diperlukan.']);
            }
            foreach ($data['records'] as $record) {
                $records[$record['student_id']]->update(['status' => $record['status'], 'note' => $record['note'] ?? null]);
            }
            unset($data['records'], $data['version']);
            $session->fill($data)->forceFill(['updated_by' => $actor->id, 'version' => $session->version + 1])->save();
            $this->recordEvent($session, $actor, $class, AuditAction::AttendanceUpdated);

            return $this->detail($class, $id);
        }, 3);
    }

    public function transition(User $actor, int $id, int $version, bool $finalize, ?string $reason = null): array
    {
        return DB::transaction(function () use ($actor, $id, $version, $finalize, $reason) {
            $class = $this->classFor($actor, true);
            $session = $this->sessions($class)->lockForUpdate()->findOrFail($id);
            $this->requireVersion($session, $version);
            if ($session->status !== ($finalize ? 'draft' : 'finalized')) {
                $this->conflict('Status absensi sudah berubah. Muat ulang sebelum melanjutkan.');
            }
            if ($finalize && (! $session->records()->exists() || $session->records()->whereNull('status')->exists())) {
                throw ValidationException::withMessages(['records' => 'Isi status seluruh mahasiswa sebelum finalisasi. Tidak ada status hadir otomatis.']);
            }
            $session->forceFill([
                'status' => $finalize ? 'finalized' : 'draft', 'version' => $session->version + 1,
                'updated_by' => $actor->id, 'finalized_at' => $finalize ? now() : null,
            ])->save();
            $this->recordEvent($session, $actor, $class, $finalize ? AuditAction::AttendanceFinalized : AuditAction::AttendanceReopened, $reason);

            return $this->detail($class, $id);
        }, 3);
    }

    public function summary(ClassRoom $class, array $filters): array
    {
        return DB::transaction(function () use ($class, $filters) {
            $sessions = $this->sessions($class, $filters)->where('status', 'finalized');
            $sessionCount = (clone $sessions)->count();
            $totals = array_fill_keys([...AttendanceRecord::STATUSES, 'total'], 0);
            $students = [];
            $records = AttendanceRecord::query()->whereIn('session_id', $sessions->select('id'))->orderBy('id');
            foreach ($records->cursor() as $record) {
                $students[$record->student_id] ??= ['student_id' => $record->student_id, 'user_id' => $record->user_id, ...$totals];
                $students[$record->student_id]['name'] = $record->name;
                $students[$record->student_id]['nim'] = $record->nim;
                $students[$record->student_id][$record->status]++;
                $students[$record->student_id]['total']++;
            }
            foreach ($students as &$student) {
                foreach (array_keys($totals) as $key) {
                    $totals[$key] += $student[$key];
                }
                $student['attendance_rate'] = round($student['present'] / $student['total'] * 100, 2);
            }
            unset($student);
            $totals['attendance_rate'] = $totals['total'] ? round($totals['present'] / $totals['total'] * 100, 2) : null;

            return [
                'class' => $this->classData($class),
                'filters' => array_intersect_key($filters, array_flip(['course_id', 'date_from', 'date_to'])),
                'sessions_count' => $sessionCount, 'totals' => $totals,
                'students' => collect($students)->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values(),
            ];
        });
    }

    private function validateMeeting(ClassRoom $class, int $courseId, array $data, ?int $exceptId = null): void
    {
        if ($data['held_on'] < $class->academicTerm->starts_on->toDateString() || $data['held_on'] > $class->academicTerm->ends_on->toDateString()) {
            throw ValidationException::withMessages(['held_on' => 'Tanggal pertemuan harus berada dalam periode akademik kelas.']);
        }
        if (($data['meeting_number'] ?? 0) < 1 || $data['meeting_number'] > 100) {
            throw ValidationException::withMessages(['meeting_number' => 'Nomor pertemuan harus 1 sampai 100.']);
        }
        if (AttendanceSession::query()->where('course_id', $courseId)->where('meeting_number', $data['meeting_number'])
            ->when($exceptId !== null, fn (Builder $q) => $q->whereKeyNot($exceptId))->lockForUpdate()->first(['id']) !== null) {
            throw ValidationException::withMessages(['meeting_number' => 'Nomor pertemuan sudah digunakan untuk mata kuliah ini.']);
        }
    }

    private function requireVersion(AttendanceSession $session, int $version): void
    {
        if ($session->version !== $version) {
            $this->conflict('Absensi telah diubah dari halaman lain. Perubahan Anda belum disimpan; muat ulang data terbaru agar tidak menimpa perubahan tersebut.');
        }
    }

    private function conflict(string $message): never
    {
        throw new HttpResponseException(response()->json([
            'message' => $message, 'error' => ['code' => 'ATTENDANCE_CONFLICT'],
        ], 409, ['Cache-Control' => 'private, no-store']));
    }

    private function recordEvent(AttendanceSession $session, User $actor, ClassRoom $class, AuditAction $action, ?string $reason = null): void
    {
        $session->events()->create(['actor_id' => $actor->id, 'action' => $action->value, 'reason' => $reason]);
        $this->audit->record($action, $actor, $session, $class->id, ['status' => $session->status]);
    }
}
