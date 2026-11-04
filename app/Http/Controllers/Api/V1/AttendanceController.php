<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\SaveSessionRequest;
use App\Models\AttendanceCourse;
use App\Services\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceController extends Controller
{
    public function __construct(private readonly AttendanceService $attendance) {}

    public function index(Request $request): JsonResponse
    {
        $class = $this->attendance->classFor($request->user());

        return $this->respond([
            'class' => $this->attendance->classData($class),
            'courses' => AttendanceCourse::query()->where('class_id', $class->id)->withCount('sessions')
                ->orderByDesc('is_active')->orderBy('name')->orderBy('id')->get()->map(fn ($course) => $this->courseData($course)),
            'roster' => $this->attendance->roster($class),
            'timezone' => 'Asia/Jakarta', 'today' => now('Asia/Jakarta')->toDateString(),
        ], 'Ruang absensi kelas Anda.');
    }

    public function storeCourse(Request $request): JsonResponse
    {
        $this->attendance->classFor($request->user());
        $course = $this->attendance->saveCourse($request->user(), $this->courseInput($request));

        return $this->respond($this->courseData($course), 'Mata kuliah ditambahkan.', 201);
    }

    public function updateCourse(Request $request, int $course): JsonResponse
    {
        $this->attendance->classFor($request->user());
        $result = $this->attendance->saveCourse($request->user(), $this->courseInput($request), $course);

        return $this->respond($this->courseData($result), 'Mata kuliah disimpan. Riwayat pertemuan tidak berubah.');
    }

    public function sessions(Request $request): JsonResponse
    {
        $class = $this->attendance->classFor($request->user());
        $paginator = $this->attendance->withDetails($this->attendance->sessions($class, $this->filters($request)))
            ->orderByDesc('held_on')->orderByDesc('id')->paginate($this->perPage($request));

        return $this->respond($paginator->getCollection()->map(fn ($session) => $this->attendance->sessionData($session)),
            'Riwayat pertemuan kelas.', 200, ['pagination' => $this->paginationMeta($paginator)]);
    }

    public function store(SaveSessionRequest $request): JsonResponse
    {
        return $this->respond($this->attendance->create($request->user(), $request->validated()), 'Draf pertemuan dibuat. Isi status setiap mahasiswa.', 201);
    }

    public function show(Request $request, int $session): JsonResponse
    {
        return $this->respond($this->attendance->detail($this->attendance->classFor($request->user()), $session), 'Detail absensi pertemuan.');
    }

    public function update(SaveSessionRequest $request, int $session): JsonResponse
    {
        return $this->respond($this->attendance->save($request->user(), $session, $request->validated()), 'Draf absensi disimpan.');
    }

    public function finalize(Request $request, int $session): JsonResponse
    {
        $this->attendance->classFor($request->user());
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);

        return $this->respond($this->attendance->transition($request->user(), $session, $data['version'], true), 'Absensi difinalisasi dan masuk ke rekap.');
    }

    public function reopen(Request $request, int $session): JsonResponse
    {
        $this->attendance->classFor($request->user());
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ], ['reason.min' => 'Jelaskan alasan koreksi minimal 10 karakter.']);

        return $this->respond($this->attendance->transition($request->user(), $session, $data['version'], false, $data['reason']),
            'Absensi dibuka untuk koreksi. Finalisasi kembali agar masuk ke rekap.');
    }

    public function summary(Request $request): JsonResponse
    {
        $class = $this->attendance->classFor($request->user());

        return $this->respond($this->attendance->summary($class, $this->filters($request, false)), 'Rekap hanya menghitung pertemuan yang sudah final.');
    }

    public function export(Request $request): StreamedResponse
    {
        $class = $this->attendance->classFor($request->user());
        $filters = $this->filters($request, false);
        $summary = $this->attendance->summary($class, $filters);
        $course = ! empty($filters['course_id']) ? AttendanceCourse::query()->where('class_id', $class->id)->findOrFail($filters['course_id']) : null;

        return response()->streamDownload(function () use ($summary, $class, $filters, $course): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Kelas', 'Periode akademik', 'Mata kuliah', 'Dosen (katalog)', 'Dari tanggal', 'Sampai tanggal', 'NIM', 'Nama', 'Hadir', 'Izin', 'Sakit', 'Alpa', 'Total pertemuan mahasiswa', 'Kehadiran (%)'], ',', '"', '');
            foreach ($summary['students'] as $student) {
                $row = [
                    $class->name, $class->academicTerm->name, $course?->name ?? 'Semua mata kuliah', $course?->lecturer_name ?? '',
                    $filters['date_from'] ?? '', $filters['date_to'] ?? '',
                    $student['nim'] === null ? '' : "'".$student['nim'], $student['name'],
                    $student['present'], $student['excused'], $student['sick'], $student['absent'], $student['total'], $student['attendance_rate'],
                ];
                $row = array_map(static function ($value): string {
                    $value = (string) $value;

                    return preg_match('/^[\s\p{C}]*[=+\-@]/u', $value) ? "'".$value : $value;
                }, $row);
                fputcsv($output, $row, ',', '"', '');
            }
            fclose($output);
        }, 'rekap-absensi-'.$class->id.'-'.now('Asia/Jakarta')->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function courseInput(Request $request): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:160'],
            'lecturer_name' => ['required', 'string', 'max:160'],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'code.required' => 'Kode mata kuliah wajib diisi.',
            'name.required' => 'Nama mata kuliah wajib diisi.',
            'lecturer_name.required' => 'Nama dosen pengampu wajib diisi.',
        ]);
    }

    private function courseData(AttendanceCourse $course): array
    {
        return $course->only(['id', 'code', 'name', 'lecturer_name', 'is_active', 'sessions_count']);
    }

    private function filters(Request $request, bool $includeStatus = true): array
    {
        $rules = [
            'course_id' => ['nullable', 'integer', 'min:1'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('date_from'), ['after_or_equal:date_from'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
        if ($includeStatus) {
            $rules['status'] = ['nullable', Rule::in(['draft', 'finalized'])];
        }

        return $request->validate($rules, ['date_to.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal awal.']);
    }
}
