<?php

namespace Tests\Feature;

use App\Enums\IdentityStatus;
use App\Enums\UserRole;
use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Domain;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/v1/attendance';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-03 10:00:00', 'Asia/Jakarta'));
        Domain::seedCatalog();
    }

    public function test_all_attendance_endpoints_return_401_without_authentication(): void
    {
        foreach ($this->endpoints(['id' => 1], ['id' => 1]) as [$method, $path, $payload]) {
            $this->json($method, self::API.$path, $payload)
                ->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
        }

        $this->assertDatabaseCount('attendance_courses', 0);
        $this->assertDatabaseCount('attendance_sessions', 0);
        $this->assertDatabaseCount('attendance_events', 0);
    }

    public static function nonLeaderRoles(): array
    {
        return [
            'student' => [UserRole::Student],
            'lecturer coordinator' => [UserRole::LecturerCoordinator],
            'identity verifier' => [UserRole::IdentityVerifier],
            'super admin' => [UserRole::SuperAdmin],
        ];
    }

    #[DataProvider('nonLeaderRoles')]
    public function test_other_roles_return_403_even_with_permission_and_an_assignment(UserRole $role): void
    {
        // Exercise each authorization boundary without the separate repeated-denial cooldown masking it.
        config(['security.repeat_threshold' => 1000]);
        [$class, $leader] = $this->leader();
        $course = $this->course();
        $session = $this->createSession($course);
        $actor = Domain::user($role);
        Domain::member($actor, $class);
        $leader->classAdministratorAssignments()->firstOrFail()->update(['user_id' => $actor->id]);
        $actor->role->permissions()->syncWithoutDetaching([Permission::where('key', 'attendance.manage')->value('id')]);
        $before = $this->attendanceState();

        $this->actingAs($actor, 'sanctum');
        foreach ($this->endpoints($course, $session) as [$method, $path, $payload]) {
            $this->json($method, self::API.$path, $payload)->assertForbidden();
        }

        $this->assertSame($before, $this->attendanceState());
    }

    public function test_leader_access_returns_403_after_any_required_qualification_is_lost(): void
    {
        config(['security.repeat_threshold' => 1000]);
        [$class, $leader] = $this->leader();
        $otherClass = Domain::classRoom();
        $course = $this->course();
        $session = $this->createSession($course);
        $assignment = $leader->classAdministratorAssignments()->firstOrFail();
        $membership = $leader->activeMembership()->firstOrFail();
        $permission = Permission::where('key', 'attendance.manage')->value('id');
        $before = $this->attendanceState();
        $disqualifications = [
            'unverified identity' => fn () => $leader->forceFill(['identity_status' => IdentityStatus::Unverified])->save(),
            'inactive account' => fn () => $leader->forceFill(['is_active' => false])->save(),
            'inactive class' => fn () => $class->update(['is_active' => false]),
            'missing permission' => fn () => $leader->role->permissions()->detach($permission),
            'assignment to a different class' => fn () => $assignment->update(['class_id' => $otherClass->id]),
            'future assignment' => fn () => $assignment->update(['starts_at' => now()->addDay()]),
            'expired assignment' => fn () => $assignment->update(['starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]),
            'revoked membership' => fn () => $membership->update(['status' => 'revoked']),
            'pending membership' => fn () => $membership->update(['status' => 'pending']),
        ];

        foreach ($disqualifications as $label => $disqualify) {
            $leader->forceFill(['identity_status' => IdentityStatus::Verified, 'is_active' => true])->save();
            $class->update(['is_active' => true]);
            $membership->update(['status' => 'active']);
            $assignment->update(['class_id' => $class->id, 'starts_at' => now(), 'ends_at' => null]);
            $leader->role->permissions()->syncWithoutDetaching([$permission]);
            $disqualify();
            $this->actingAs($leader->refresh(), 'sanctum');

            foreach ($this->endpoints($course, $session) as [$method, $path, $payload]) {
                $this->json($method, self::API.$path, $payload)->assertForbidden();
            }
            $this->assertSame($before, $this->attendanceState(), $label);
        }
    }

    public function test_foreign_courses_sessions_rosters_summaries_and_exports_cannot_escape_class_scope(): void
    {
        [$class, $leader] = $this->leader();
        $ownCourse = $this->course();
        [$otherClass] = $this->leader();
        $outsider = Domain::user(UserRole::Student, ['name' => 'Foreign roster secret', 'nim' => 'FOREIGN']);
        Domain::member($outsider, $otherClass);
        $foreignCourse = $this->course(['code' => 'OTHER', 'name' => 'Foreign course secret']);
        $foreignSession = $this->finalizedSession($foreignCourse);
        $before = $this->attendanceState();

        $this->actingAs($leader, 'sanctum');
        $index = $this->getJson(self::API.'?class_id='.$otherClass->id)->assertOk()
            ->assertJsonPath('data.class.id', $class->id)->assertJsonCount(1, 'data.courses')
            ->assertJsonPath('data.courses.0.id', $ownCourse['id'])->assertJsonCount(1, 'data.roster');
        $this->assertSame([$leader->id], array_column($index->json('data.roster'), 'user_id'));
        foreach (['/sessions', '/summary', '/export'] as $path) {
            $this->getJson(self::API.$path.'?course_id='.$foreignCourse['id'])
                ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        }
        $this->getJson(self::API.'/sessions?class_id='.$otherClass->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::API.'/summary?class_id='.$otherClass->id)->assertOk()->assertJsonCount(0, 'data.students');
        $csv = $this->getJson(self::API.'/export?class_id='.$otherClass->id)->assertOk()->streamedContent();
        $this->assertStringNotContainsString('Foreign roster secret', $csv);
        $this->patchJson(self::API.'/courses/'.$foreignCourse['id'], $this->courseInput())->assertNotFound();
        $this->postJson(self::API.'/sessions', $this->sessionInput($foreignCourse))->assertNotFound();
        $this->getJson(self::API.'/sessions/'.$foreignSession['id'])->assertNotFound();
        $this->patchJson(self::API.'/sessions/'.$foreignSession['id'], $this->saveInput($foreignSession))->assertNotFound();
        foreach (['finalize', 'reopen'] as $action) {
            $this->postJson(self::API.'/sessions/'.$foreignSession['id'].'/'.$action, [
                'version' => $foreignSession['version'], 'reason' => 'Attempted foreign correction.',
            ])->assertNotFound();
        }

        $this->assertSame($before, $this->attendanceState());
    }

    public function test_course_create_edit_and_archive_preserve_existing_session_metadata(): void
    {
        [$class, $leader] = $this->leader();
        $otherClass = Domain::classRoom();
        $this->postJson(self::API.'/courses', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['code', 'name', 'lecturer_name'], 'error.details');
        $course = $this->course(['class_id' => $otherClass->id]);
        $session = $this->createSession($course);
        $this->assertDatabaseHas('attendance_courses', ['id' => $course['id'], 'class_id' => $class->id]);
        $this->postJson(self::API.'/courses', $this->courseInput())->assertUnprocessable()
            ->assertJsonValidationErrors(['code' => 'Kode mata kuliah sudah digunakan di kelas ini.'], 'error.details');
        $otherCourse = $this->course(['code' => 'IF202']);
        $before = $this->attendanceState();
        $this->patchJson(self::API.'/courses/'.$course['id'], $this->courseInput(['code' => $otherCourse['code']]))
            ->assertUnprocessable()->assertJsonValidationErrors(['code'], 'error.details');
        $this->assertSame($before, $this->attendanceState());

        $updated = $this->courseInput(['code' => 'IF101-NEW', 'name' => 'New course name', 'lecturer_name' => 'New lecturer']);
        $this->patchJson(self::API.'/courses/'.$course['id'], $updated)->assertOk()->assertJsonPath('data.name', 'New course name');
        $this->patchJson(self::API.'/courses/'.$course['id'], $updated + ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.sessions_count', 1);
        $before = $this->attendanceState();
        $this->postJson(self::API.'/sessions', $this->sessionInput($course, ['meeting_number' => 2]))
            ->assertUnprocessable()->assertJsonValidationErrors(['course_id'], 'error.details');
        $this->assertSame($before, $this->attendanceState());
        $this->getJson(self::API.'/sessions/'.$session['id'])->assertOk()->assertJsonPath('data.course', $session['course']);

        $this->assertDatabaseHas('attendance_courses', ['id' => $course['id'], 'name' => 'New course name', 'is_active' => false]);
        $this->assertDatabaseHas('attendance_sessions', ['id' => $session['id'], 'course_code' => 'IF101', 'course_name' => 'Database Systems', 'lecturer_name' => 'Dr Original']);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $leader->id, 'class_id' => $class->id, 'subject_id' => $course['id'], 'action' => 'attendance_course_saved']);
        $this->patchJson(self::API.'/courses/'.$course['id'], $updated + ['is_active' => true])->assertOk();
        $newSession = $this->createSession($course, ['meeting_number' => 2]);
        $this->assertSame('New course name', $newSession['course']['name']);
    }

    public function test_session_validation_returns_422_for_duplicate_meetings_term_dates_future_dates_and_invalid_times(): void
    {
        $this->travelTo(Carbon::parse('2026-11-03 00:30:00', 'Asia/Jakarta'));
        [$class] = $this->leader();
        $class->academicTerm->update(['starts_on' => '2026-10-01', 'ends_on' => '2026-11-02']);
        $course = $this->course();
        $this->postJson(self::API.'/sessions', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['course_id', 'meeting_number', 'held_on'], 'error.details');
        $session = $this->createSession($course, ['held_on' => '2026-10-01']);
        $before = $this->attendanceState();
        $invalid = [
            [['meeting_number' => 1], 'meeting_number'],
            [['held_on' => '2026-09-30'], 'held_on'],
            [['held_on' => '2026-11-03'], 'held_on'],
            [['held_on' => '2026-11-04'], 'held_on'],
            [['held_on' => '2026-02-30'], 'held_on'],
            [['starts_at' => '09:00', 'ends_at' => null], 'ends_at'],
            [['starts_at' => null, 'ends_at' => '10:00'], 'starts_at'],
            [['starts_at' => '09:00', 'ends_at' => '09:00'], 'ends_at'],
            [['starts_at' => '23:00', 'ends_at' => '01:00'], 'ends_at'],
            [['starts_at' => '25:00', 'ends_at' => '26:00'], 'starts_at'],
            [['meeting_number' => 0], 'meeting_number'],
            [['meeting_number' => 101], 'meeting_number'],
        ];

        foreach ($invalid as [$overrides, $field]) {
            $this->postJson(self::API.'/sessions', $this->sessionInput($course, $overrides + ['meeting_number' => 2]))
                ->assertUnprocessable()->assertJsonValidationErrors([$field], 'error.details');
            $this->assertSame($before, $this->attendanceState());
        }
        $second = $this->createSession($course, ['meeting_number' => 2, 'held_on' => '2026-11-02', 'starts_at' => null, 'ends_at' => null]);
        $before = $this->attendanceState();
        $this->patchJson(self::API.'/sessions/'.$second['id'], $this->saveInput($second, ['meeting_number' => 1]))
            ->assertUnprocessable()->assertJsonValidationErrors(['meeting_number'], 'error.details');
        $this->patchJson(self::API.'/sessions/'.$second['id'], $this->saveInput($second, ['held_on' => '2026-11-04']))
            ->assertUnprocessable()->assertJsonValidationErrors([
                'held_on' => 'Tanggal absensi tidak boleh melewati hari ini (WIB).',
            ], 'error.details');
        $this->assertSame($before, $this->attendanceState());
        $this->patchJson(self::API.'/sessions/'.$session['id'], $this->saveInput($session))
            ->assertOk()->assertJsonPath('data.meeting_number', 1);
        $differentCourse = $this->course(['code' => 'IF202']);
        $this->createSession($differentCourse, ['meeting_number' => 1]);
        $this->assertDatabaseCount('attendance_sessions', 3);
        $class->academicTerm->update(['ends_on' => '2026-11-03']);
        $today = $this->createSession($course, ['meeting_number' => 3, 'held_on' => '2026-11-03']);
        $this->assertSame('2026-11-03', $today['held_on']);
    }

    public function test_new_session_snapshots_only_eligible_students_and_never_marks_attendance_automatically(): void
    {
        [$class, $leader] = $this->leader();
        $student = Domain::user(UserRole::Student, ['name' => 'Eligible student', 'nim' => 'USER-NIM']);
        Domain::member($student, $class)->update(['nim' => '00001234']);
        $excluded = [
            Domain::user(UserRole::Student, ['name' => 'Pending membership']),
            Domain::user(UserRole::Student, ['name' => 'Revoked membership']),
            Domain::user(UserRole::Student, ['name' => 'Inactive user']),
            Domain::user(UserRole::Student, ['name' => 'Unverified user', 'identity_status' => IdentityStatus::PendingVerification]),
            Domain::user(UserRole::LecturerCoordinator),
            Domain::user(UserRole::IdentityVerifier),
            Domain::user(UserRole::SuperAdmin),
        ];
        foreach ($excluded as $user) {
            Domain::member($user, $class);
        }
        $excluded[0]->memberships()->update(['status' => 'pending']);
        $excluded[1]->memberships()->update(['status' => 'revoked']);
        $excluded[2]->forceFill(['is_active' => false])->save();
        $outsider = Domain::user(UserRole::Student, ['name' => 'Other class student']);
        Domain::member($outsider, Domain::classRoom());
        $course = $this->course();

        $index = $this->getJson(self::API)->assertOk()->assertJsonCount(2, 'data.roster');
        $this->assertEqualsCanonicalizing([$leader->id, $student->id], array_column($index->json('data.roster'), 'user_id'));
        $session = $this->createSession($course, [
            'status' => 'finalized', 'version' => 99, 'created_by' => $outsider->id,
            'course_name' => 'Forged course', 'lecturer_name' => 'Forged lecturer',
            'records' => [['user_id' => $outsider->id, 'status' => 'present']],
        ]);

        $this->assertSame('draft', $session['status']);
        $this->assertSame(1, $session['version']);
        $this->assertSame($leader->id, $session['created_by']['id']);
        $this->assertSame('Dr Original', $session['lecturer_name']);
        $this->assertSame(['total' => 2, 'unmarked' => 2, 'present' => 0, 'excused' => 0, 'sick' => 0, 'absent' => 0], $session['counts']);
        $this->assertEqualsCanonicalizing([$leader->id, $student->id], array_column($session['records'], 'user_id'));
        foreach ($session['records'] as $record) {
            $this->assertNull($record['status']);
            $this->assertNull($record['note']);
            $this->assertDatabaseHas('attendance_records', ['session_id' => $session['id']] + $record);
        }
        $this->assertDatabaseHas('attendance_records', ['session_id' => $session['id'], 'user_id' => $student->id, 'nim' => '00001234']);
        $this->assertDatabaseCount('attendance_records', 2);
    }

    public function test_save_returns_422_and_is_atomic_unless_every_snapshot_id_occurs_exactly_once(): void
    {
        [$class] = $this->leader();
        Domain::member(Domain::user(UserRole::Student), $class);
        $outsider = Domain::user(UserRole::Student);
        Domain::member($outsider, Domain::classRoom());
        $course = $this->course();
        $session = $this->createSession($course);
        $payload = $this->saveInput($session, ['topic' => 'Must not be saved']);
        $records = $payload['records'];
        $before = $this->attendanceState();
        $invalid = [
            'missing student' => [[$records[0]], 'records'],
            'duplicate student' => [[$records[0], $records[0]], 'records.1.user_id'],
            'foreign student' => [[$records[0], ['user_id' => $outsider->id, 'status' => 'absent']], 'records'],
            'extra student' => [[...$records, ['user_id' => $outsider->id, 'status' => 'absent']], 'records'],
            'missing status key' => [[$records[0], ['user_id' => $records[1]['user_id']]], 'records.1.status'],
            'unknown status' => [[$records[0], ['user_id' => $records[1]['user_id'], 'status' => 'late']], 'records.1.status'],
            'overlong note' => [[$records[0], $records[1] + ['note' => str_repeat('x', 501)]], 'records.1.note'],
            'forged snapshot name' => [[$records[0], $records[1] + ['name' => 'Forged name']], 'records.1'],
            'non-list records' => [[1 => $records[0], 2 => $records[1]], 'records'],
        ];

        foreach ($invalid as $label => [$rows, $field]) {
            $this->patchJson(self::API.'/sessions/'.$session['id'], array_replace($payload, ['records' => $rows]))
                ->assertUnprocessable()->assertJsonValidationErrors([$field], 'error.details');
            $this->assertSame($before, $this->attendanceState(), $label);
        }
    }

    public function test_valid_save_persists_each_status_and_note_without_changing_protected_metadata(): void
    {
        [$class, $leader] = $this->leader();
        foreach (['A Student', 'B Student', 'C Student', 'D Student'] as $name) {
            Domain::member(Domain::user(UserRole::Student, ['name' => $name]), $class);
        }
        $course = $this->course();
        $otherCourse = $this->course(['code' => 'IF202']);
        $session = $this->createSession($course);
        $records = array_map(fn ($record, $status) => [
            'user_id' => $record['user_id'], 'status' => $status, 'note' => 'Recorded explanation',
        ], $session['records'], ['present', 'excused', 'sick', 'absent', null]);

        $saved = $this->patchJson(self::API.'/sessions/'.$session['id'], $this->saveInput($session, [
            'records' => array_reverse($records), 'topic' => 'Transactions', 'notes' => 'Draft notes',
            'lecturer_name' => 'Substitute lecturer', 'course_id' => $otherCourse['id'],
            'course_code' => 'FORGED', 'course_name' => 'FORGED', 'created_by' => 999,
            'updated_by' => 999, 'status' => 'finalized', 'finalized_at' => now()->toIso8601String(),
        ]))->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.course_id', $course['id'])->assertJsonPath('data.course.code', 'IF101')
            ->assertJsonPath('data.course.name', 'Database Systems')->assertJsonPath('data.lecturer_name', 'Substitute lecturer')
            ->assertJsonPath('data.finalized_at', null)->assertJsonPath('data.created_by.id', $leader->id)
            ->assertJsonPath('data.updated_by.id', $leader->id)->assertJsonPath('data.counts', [
                'total' => 5, 'unmarked' => 1, 'present' => 1, 'excused' => 1, 'sick' => 1, 'absent' => 1,
            ])->json('data');

        foreach ($records as $record) {
            $this->assertDatabaseHas('attendance_records', ['session_id' => $session['id']] + $record);
        }
        $this->assertDatabaseHas('attendance_sessions', ['id' => $session['id'], 'version' => 2, 'topic' => 'Transactions', 'notes' => 'Draft notes', 'status' => 'draft']);
        $this->assertSame(['attendance_created', 'attendance_updated'], array_column($saved['history'], 'action'));
        $before = $this->attendanceState();
        $this->postJson(self::API.'/sessions/'.$session['id'].'/finalize', ['version' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors(['records'], 'error.details');
        $this->assertSame($before, $this->attendanceState());
        $this->getJson(self::API.'/summary')->assertOk()->assertJsonPath('data.sessions_count', 0)
            ->assertJsonPath('data.totals.total', 0)->assertJsonPath('data.totals.attendance_rate', null);
    }

    public function test_finalize_requires_all_marks_and_reopen_requires_a_reason_with_event_and_audit_history(): void
    {
        [$class, $leader] = $this->leader();
        $course = $this->course();
        $session = $this->createSession($course);
        $before = $this->attendanceState();
        $this->postJson(self::API.'/sessions/'.$session['id'].'/finalize', ['version' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors([
                'records' => 'Isi status seluruh mahasiswa sebelum finalisasi. Tidak ada status hadir otomatis.',
            ], 'error.details');
        $this->assertSame($before, $this->attendanceState());
        $saved = $this->patchJson(self::API.'/sessions/'.$session['id'], $this->saveInput($session))->assertOk()->json('data');
        $finalized = $this->postJson(self::API.'/sessions/'.$session['id'].'/finalize', ['version' => $saved['version']])
            ->assertOk()->assertJsonPath('data.status', 'finalized')->assertJsonPath('data.version', 3)->json('data');
        $this->assertNotNull($finalized['finalized_at']);
        $before = $this->attendanceState();
        $this->patchJson(self::API.'/sessions/'.$session['id'], $this->saveInput($finalized, ['topic' => 'Illegal edit']))
            ->assertConflict()->assertJsonPath('error.code', 'ATTENDANCE_CONFLICT');
        $this->postJson(self::API.'/sessions/'.$session['id'].'/finalize', ['version' => 3])->assertConflict();
        foreach ([null, '', 'short', str_repeat('x', 1001)] as $reason) {
            $this->postJson(self::API.'/sessions/'.$session['id'].'/reopen', ['version' => 3, 'reason' => $reason])
                ->assertUnprocessable()->assertJsonValidationErrors(['reason'], 'error.details');
        }
        $this->assertSame($before, $this->attendanceState());

        $reason = 'Correct an incorrectly recorded absence.';
        $reopened = $this->postJson(self::API.'/sessions/'.$session['id'].'/reopen', ['version' => 3, 'reason' => $reason])
            ->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.version', 4)
            ->assertJsonPath('data.finalized_at', null)->assertJsonPath('data.history.3.reason', $reason)->json('data');
        $this->getJson(self::API.'/summary')->assertOk()->assertJsonPath('data.sessions_count', 0);
        $this->assertDatabaseHas('attendance_events', ['session_id' => $session['id'], 'actor_id' => $leader->id, 'action' => 'attendance_reopened', 'reason' => $reason]);
        foreach (['attendance_created', 'attendance_updated', 'attendance_finalized', 'attendance_reopened'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['actor_id' => $leader->id, 'class_id' => $class->id, 'subject_type' => 'AttendanceSession', 'subject_id' => $session['id'], 'action' => $action]);
        }
        $corrected = $this->patchJson(self::API.'/sessions/'.$session['id'], $this->saveInput($reopened))->assertOk()->json('data');
        $this->postJson(self::API.'/sessions/'.$session['id'].'/finalize', ['version' => $corrected['version']])
            ->assertOk()->assertJsonPath('data.version', 6)->assertJsonPath('data.history.*.action', [
                'attendance_created', 'attendance_updated', 'attendance_finalized', 'attendance_reopened', 'attendance_updated', 'attendance_finalized',
            ]);
        $this->assertDatabaseHas('attendance_sessions', ['id' => $session['id'], 'version' => 6, 'status' => 'finalized']);
        $this->assertDatabaseCount('attendance_events', 6);
    }

    public function test_stale_save_finalize_and_reopen_return_409_without_changing_any_attendance_state(): void
    {
        $this->leader();
        $course = $this->course();
        $session = $this->createSession($course);
        $saved = $this->patchJson(self::API.'/sessions/'.$session['id'], $this->saveInput($session, ['topic' => 'Winning edit']))
            ->assertOk()->json('data');
        $before = $this->attendanceState();

        foreach ([null, 0] as $version) {
            $this->patchJson(self::API.'/sessions/'.$session['id'], $this->saveInput($saved, ['version' => $version]))
                ->assertUnprocessable()->assertJsonValidationErrors(['version'], 'error.details');
            $this->postJson(self::API.'/sessions/'.$session['id'].'/finalize', ['version' => $version])
                ->assertUnprocessable()->assertJsonValidationErrors(['version'], 'error.details');
        }

        $this->patchJson(self::API.'/sessions/'.$session['id'], $this->saveInput($session, ['topic' => 'Stale overwrite']))
            ->assertConflict()->assertJsonPath('error.code', 'ATTENDANCE_CONFLICT');
        $this->postJson(self::API.'/sessions/'.$session['id'].'/finalize', ['version' => 1])
            ->assertConflict()->assertJsonPath('error.code', 'ATTENDANCE_CONFLICT');
        $this->assertSame($before, $this->attendanceState());
        $finalized = $this->postJson(self::API.'/sessions/'.$session['id'].'/finalize', ['version' => $saved['version']])
            ->assertOk()->json('data');
        $before = $this->attendanceState();
        $this->postJson(self::API.'/sessions/'.$session['id'].'/reopen', ['version' => 2, 'reason' => 'Correction from a stale browser.'])
            ->assertConflict()->assertJsonPath('error.code', 'ATTENDANCE_CONFLICT');
        $this->assertSame($before, $this->attendanceState());
        $this->getJson(self::API.'/sessions/'.$session['id'])->assertOk()->assertJsonPath('data', $finalized);
    }

    public function test_existing_snapshot_survives_membership_name_nim_and_course_changes(): void
    {
        [$class] = $this->leader();
        $student = Domain::user(UserRole::Student, ['name' => 'Original student', 'nim' => '00000123']);
        $membership = Domain::member($student, $class);
        $course = $this->course();
        $original = $this->finalizedSession($course);
        $membership->update(['status' => 'revoked', 'nim' => 'NEW-MEMBERSHIP']);
        $student->update(['name' => 'Renamed student', 'nim' => 'NEW-USER']);
        Domain::member($student, Domain::classRoom());
        $newStudent = Domain::user(UserRole::Student, ['name' => 'New class member']);
        Domain::member($newStudent, $class);
        $this->patchJson(self::API.'/courses/'.$course['id'], $this->courseInput(['name' => 'Renamed course', 'lecturer_name' => 'New lecturer']))->assertOk();

        $this->getJson(self::API.'/sessions/'.$original['id'])->assertOk()->assertJsonPath('data', $original);
        $next = $this->createSession($course, ['meeting_number' => 2]);
        $this->assertContains($newStudent->id, array_column($next['records'], 'user_id'));
        $this->assertNotContains($student->id, array_column($next['records'], 'user_id'));
        $this->assertSame('Renamed course', $next['course']['name']);
        $reopened = $this->postJson(self::API.'/sessions/'.$original['id'].'/reopen', [
            'version' => $original['version'], 'reason' => 'Correct the historical snapshot.',
        ])->assertOk()->json('data');
        $saved = $this->patchJson(self::API.'/sessions/'.$original['id'], $this->saveInput($reopened))->assertOk()->json('data');
        $this->assertSame($original['records'], $saved['records']);
        $this->assertSame($original['course'], $saved['course']);
        $this->assertDatabaseHas('attendance_records', ['session_id' => $original['id'], 'user_id' => $student->id, 'name' => 'Original student', 'nim' => '00000123']);
    }

    public function test_summary_counts_finalized_snapshots_with_historical_denominators_and_course_date_filters(): void
    {
        [$class, $leader] = $this->leader();
        $departing = Domain::user(UserRole::Student, ['name' => 'Departing student']);
        $membership = Domain::member($departing, $class);
        $course = $this->course();
        $first = $this->finalizedSession($course, 1, '2026-10-10', [$leader->id => 'present', $departing->id => 'excused']);
        $membership->update(['status' => 'revoked']);
        $joining = Domain::user(UserRole::Student, ['name' => 'Joining student']);
        Domain::member($joining, $class);
        $this->finalizedSession($course, 2, '2026-10-20', [$leader->id => 'sick', $joining->id => 'absent']);
        $secondCourse = $this->course(['code' => 'IF202']);
        $this->finalizedSession($secondCourse, 1, '2026-10-30', [$leader->id => 'present', $joining->id => 'present']);
        $draft = $this->createSession($course, ['meeting_number' => 3, 'held_on' => '2026-11-01']);
        $this->patchJson(self::API.'/sessions/'.$draft['id'], $this->saveInput($draft))->assertOk();

        $summary = $this->getJson(self::API.'/summary')->assertOk()->assertJsonPath('data.sessions_count', 3)
            ->assertJsonPath('data.totals', ['present' => 3, 'excused' => 1, 'sick' => 1, 'absent' => 1, 'total' => 6, 'attendance_rate' => 50])
            ->assertJsonCount(3, 'data.students')->json('data');
        $students = array_column($summary['students'], null, 'user_id');
        $this->assertSame(3, $students[$leader->id]['total']);
        $this->assertSame(66.67, $students[$leader->id]['attendance_rate']);
        $this->assertSame(1, $students[$departing->id]['total']);
        $this->assertSame(0, $students[$departing->id]['attendance_rate']);
        $this->assertSame(2, $students[$joining->id]['total']);
        $this->assertSame(50, $students[$joining->id]['attendance_rate']);
        $this->getJson(self::API.'/summary?course_id='.$course['id'])->assertOk()->assertJsonPath('data.sessions_count', 2)
            ->assertJsonPath('data.totals.attendance_rate', 25)->assertJsonPath('data.totals.total', 4);
        $this->getJson(self::API.'/summary?'.http_build_query(['course_id' => $course['id'], 'date_from' => '2026-10-20', 'date_to' => '2026-10-20']))
            ->assertOk()->assertJsonPath('data.sessions_count', 1)->assertJsonPath('data.totals.total', 2)
            ->assertJsonPath('data.totals.sick', 1)->assertJsonPath('data.totals.absent', 1);
        $this->getJson(self::API.'/summary?date_from=2026-11-01')->assertOk()->assertJsonPath('data.sessions_count', 0)
            ->assertJsonPath('data.totals.attendance_rate', null)->assertJsonCount(0, 'data.students');
        $this->postJson(self::API.'/sessions/'.$first['id'].'/reopen', ['version' => $first['version'], 'reason' => 'Exclude while correcting records.'])->assertOk();
        $this->getJson(self::API.'/summary')->assertOk()->assertJsonPath('data.sessions_count', 2)
            ->assertJsonPath('data.totals.total', 4)->assertJsonCount(2, 'data.students');
    }

    public function test_session_history_paginates_stably_and_validates_filters(): void
    {
        $this->leader();
        $course = $this->course();
        $oldest = $this->createSession($course, ['held_on' => '2026-10-10']);
        $middle = $this->finalizedSession($course, 2, '2026-10-20');
        $latest = $this->createSession($course, ['meeting_number' => 3, 'held_on' => '2026-10-20']);

        $this->getJson(self::API.'/sessions?per_page=2')->assertOk()->assertJsonPath('data.*.id', [$latest['id'], $middle['id']])
            ->assertJsonPath('meta.pagination', ['current_page' => 1, 'per_page' => 2, 'total' => 3, 'last_page' => 2]);
        $this->getJson(self::API.'/sessions?per_page=2&page=2')->assertOk()->assertJsonPath('data.*.id', [$oldest['id']])
            ->assertJsonPath('meta.pagination.current_page', 2);
        $this->getJson(self::API.'/sessions?date_from=2026-10-20&date_to=2026-10-20')->assertOk()
            ->assertJsonPath('data.*.id', [$latest['id'], $middle['id']]);
        $otherCourse = $this->course(['code' => 'IF202']);
        $this->finalizedSession($otherCourse);
        $this->getJson(self::API.'/sessions?'.http_build_query([
            'course_id' => $course['id'], 'status' => 'finalized', 'date_from' => '2026-10-20', 'date_to' => '2026-10-20',
        ]))->assertOk()->assertJsonPath('data.*.id', [$middle['id']])->assertJsonPath('data.0.counts.unmarked', 0);
        $before = $this->attendanceState();
        foreach (['/sessions', '/summary', '/export'] as $path) {
            $this->getJson(self::API.$path.'?date_from=2026-10-20&date_to=2026-10-10&page=0&per_page=101&course_id=invalid')
                ->assertUnprocessable()->assertJsonValidationErrors(['date_to', 'page', 'per_page', 'course_id'], 'error.details');
        }
        $this->getJson(self::API.'/sessions?status=invalid')->assertUnprocessable()->assertJsonValidationErrors(['status'], 'error.details');
        $this->assertSame($before, $this->attendanceState());
    }

    public function test_csv_export_preserves_nims_and_neutralizes_formulas_in_every_text_column(): void
    {
        [$class, $leader] = $this->leader();
        $class->update(['name' => '=CLASS()']);
        $class->academicTerm->update(['name' => '+TERM()']);
        $leader->update(['name' => '@Leader', 'nim' => '00000001']);
        $leader->memberships()->update(['nim' => '00000001']);
        foreach ([['=FORMULA()', '=001'], ['+Formula', '0002'], ['-Formula', '0003'], ["\t=Hidden", '0004'], ["\r@Hidden", '0005'], ['Comma, "Quoted"', '0006']] as [$name, $nim]) {
            Domain::member(Domain::user(UserRole::Student, ['name' => $name, 'nim' => $nim]), $class);
        }
        $course = $this->course(['name' => '-COURSE()', 'lecturer_name' => '@LECTURER()']);
        $this->finalizedSession($course, 1, '2026-10-20');
        $this->createSession($course, ['meeting_number' => 2]);

        $response = $this->getJson(self::API.'/export?'.http_build_query([
            'course_id' => $course['id'], 'date_from' => '2026-10-20', 'date_to' => '2026-10-20',
        ]))->assertOk()->assertDownload('rekap-absensi-'.$class->id.'-2026-11-03.csv')
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, substr($csv, 3));
        rewind($stream);
        $header = fgetcsv($stream, escape: '');
        $this->assertCount(14, $header);
        $this->assertSame('NIM', $header[6]);
        $rows = [];
        while (($row = fgetcsv($stream, escape: '')) !== false) {
            $rows[$row[6]] = $row;
            $this->assertSame(["'=CLASS()", "'+TERM()", "'-COURSE()", "'@LECTURER()", '2026-10-20', '2026-10-20'], array_slice($row, 0, 6));
            $this->assertSame(['1', '0', '0', '0', '1', '100'], array_slice($row, 8));
        }
        fclose($stream);

        $this->assertCount(7, $rows);
        $this->assertSame("'@Leader", $rows["'00000001"][7]);
        $this->assertSame("'=FORMULA()", $rows["'=001"][7]);
        $this->assertSame("'+Formula", $rows["'0002"][7]);
        $this->assertSame("'-Formula", $rows["'0003"][7]);
        $this->assertSame("'\t=Hidden", $rows["'0004"][7]);
        $this->assertSame("'\r@Hidden", $rows["'0005"][7]);
        $this->assertSame('Comma, "Quoted"', $rows["'0006"][7]);
    }

    private function leader(): array
    {
        $class = Domain::classRoom();
        $leader = Domain::user(UserRole::ClassLeader, ['name' => 'Class leader', 'nim' => 'LEADER-'.$class->id]);
        Domain::leader($leader, $class);
        $this->actingAs($leader, 'sanctum');

        return [$class, $leader];
    }

    private function courseInput(array $overrides = []): array
    {
        return array_replace(['code' => 'IF101', 'name' => 'Database Systems', 'lecturer_name' => 'Dr Original'], $overrides);
    }

    private function course(array $overrides = []): array
    {
        return $this->postJson(self::API.'/courses', $this->courseInput($overrides))->assertCreated()->json('data');
    }

    private function sessionInput(array $course, array $overrides = []): array
    {
        return array_replace([
            'course_id' => $course['id'], 'meeting_number' => 1, 'held_on' => '2026-10-20',
            'starts_at' => '09:00', 'ends_at' => '10:30', 'topic' => 'Relational databases', 'notes' => null,
        ], $overrides);
    }

    private function createSession(array $course, array $overrides = []): array
    {
        return $this->postJson(self::API.'/sessions', $this->sessionInput($course, $overrides))->assertCreated()->json('data');
    }

    private function saveInput(array $session, array $overrides = []): array
    {
        return array_replace([
            'version' => $session['version'] ?? 1, 'meeting_number' => $session['meeting_number'] ?? 1,
            'held_on' => $session['held_on'] ?? '2026-10-20', 'starts_at' => $session['starts_at'] ?? null,
            'ends_at' => $session['ends_at'] ?? null, 'topic' => $session['topic'] ?? null, 'notes' => $session['notes'] ?? null,
            'lecturer_name' => $session['lecturer_name'] ?? 'Dr Original',
            'records' => array_map(fn ($record) => ['user_id' => $record['user_id'], 'status' => 'present'], $session['records'] ?? []),
        ], $overrides);
    }

    private function finalizedSession(array $course, int $meeting = 1, string $date = '2026-10-20', array $statuses = []): array
    {
        $session = $this->createSession($course, ['meeting_number' => $meeting, 'held_on' => $date]);
        $payload = $this->saveInput($session);
        foreach ($payload['records'] as &$record) {
            $record['status'] = $statuses[$record['user_id']] ?? 'present';
        }
        unset($record);
        $saved = $this->patchJson(self::API.'/sessions/'.$session['id'], $payload)->assertOk()->json('data');

        return $this->postJson(self::API.'/sessions/'.$session['id'].'/finalize', ['version' => $saved['version']])->assertOk()->json('data');
    }

    private function endpoints(array $course, array $session): array
    {
        return [
            ['GET', '', []],
            ['POST', '/courses', $this->courseInput(['code' => 'DENIED'])],
            ['PATCH', '/courses/'.$course['id'], $this->courseInput()],
            ['GET', '/sessions', []],
            ['POST', '/sessions', $this->sessionInput($course, ['meeting_number' => 99])],
            ['GET', '/sessions/'.$session['id'], []],
            ['PATCH', '/sessions/'.$session['id'], $this->saveInput($session)],
            ['POST', '/sessions/'.$session['id'].'/finalize', ['version' => $session['version'] ?? 1]],
            ['POST', '/sessions/'.$session['id'].'/reopen', ['version' => $session['version'] ?? 1, 'reason' => 'Correction with a valid reason.']],
            ['GET', '/summary', []],
            ['GET', '/export', []],
        ];
    }

    private function attendanceState(): array
    {
        $state = [];
        foreach (['attendance_courses', 'attendance_sessions', 'attendance_records', 'attendance_events'] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }
        $state['audit_logs'] = DB::table('audit_logs')->where('action', 'like', 'attendance_%')
            ->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        return $state;
    }
}
