<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ClassRoom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Domain;
use Tests\TestCase;

class AttendanceRosterTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/v1/attendance';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-03 10:00:00', 'Asia/Jakarta'));
        Domain::seedCatalog();
    }

    public function test_catalog_and_roster_stay_empty_until_manual_students_are_added(): void
    {
        [$class] = $this->leader();
        Domain::member(Domain::user(UserRole::Student), $class);

        $this->getJson(self::API)->assertOk()->assertJsonPath('data.class.semester', 5)
            ->assertJsonCount(0, 'data.roster');
        $this->getJson(self::API.'/students')->assertOk()->assertJsonCount(0, 'data');

        $this->assertDatabaseCount('attendance_students', 0);
    }

    public function test_bulk_create_preserves_optional_nims_and_duplicate_names_without_creating_or_linking_accounts(): void
    {
        [$class] = $this->leader();
        $matchingUser = Domain::user(UserRole::Student, ['name' => 'Existing account', 'nim' => '00001234']);
        Domain::member($matchingUser, $class);
        $before = $this->accountState();

        $students = $this->postJson(self::API.'/students', ['students' => [
            ['name' => 'Same student name', 'nim' => '00001234'],
            ['name' => 'Same student name'],
            ['name' => 'Blank NIM', 'nim' => ''],
            ['name' => 'Null NIM', 'nim' => null],
        ]])->assertCreated()->assertJsonCount(4, 'data')->json('data');

        $this->assertSame($before, $this->accountState());
        $this->assertDatabaseCount('attendance_students', 4);
        foreach ($students as $student) {
            $this->assertNull($student['user_id']);
            $this->assertTrue($student['is_active']);
            $this->assertDatabaseHas('attendance_students', [
                'id' => $student['id'], 'class_id' => $class->id, 'user_id' => null,
                'name' => $student['name'], 'nim' => $student['nim'], 'is_active' => true,
            ]);
        }
        $this->assertDatabaseHas('attendance_students', ['name' => 'Same student name', 'nim' => '00001234']);
        $this->assertSame(3, DB::table('attendance_students')->whereNull('nim')->count());
        $this->getJson(self::API)->assertOk()->assertJsonCount(4, 'data.roster');
    }

    public function test_zero_nim_is_text_and_an_omitted_nim_is_preserved_when_archiving(): void
    {
        $this->leader();
        $student = $this->postJson(self::API.'/students', ['students' => [['name' => 'Zero identifier', 'nim' => '0']]])
            ->assertCreated()->assertJsonPath('data.0.nim', '0')->json('data.0');

        $this->patchJson(self::API.'/students/'.$student['id'], ['name' => 'Zero identifier', 'is_active' => false])
            ->assertOk()->assertJsonPath('data.nim', '0')->assertJsonPath('data.is_active', false);
        $this->patchJson(self::API.'/students/'.$student['id'], ['name' => 'Zero identifier', 'nim' => null])
            ->assertOk()->assertJsonPath('data.nim', null);
    }

    public static function invalidBatches(): array
    {
        return [
            'missing list' => [[], 'students'],
            'empty list' => [['students' => []], 'students'],
            'over one hundred' => [['students' => array_fill(0, 101, ['name' => 'Student'])], 'students'],
            'associative list' => [['students' => ['student' => ['name' => 'Student']]], 'students'],
            'missing name after valid row' => [['students' => [['name' => 'Valid first'], ['nim' => '001']]], 'students.1.name'],
            'blank name after valid row' => [['students' => [['name' => 'Valid first'], ['name' => '   ']]], 'students.1.name'],
            'long name' => [['students' => [['name' => str_repeat('a', 256)]]], 'students.0.name'],
            'long nim' => [['students' => [['name' => 'Student', 'nim' => str_repeat('0', 33)]]], 'students.0.nim'],
            'numeric nim loses leading zeros' => [['students' => [['name' => 'Student', 'nim' => 1234]]], 'students.0.nim'],
            'non-string name' => [['students' => [['name' => ['invalid']]]], 'students.0.name'],
        ];
    }

    #[DataProvider('invalidBatches')]
    public function test_invalid_batch_returns_422_without_partial_roster_or_account_writes(array $payload, string $field): void
    {
        $this->leader();
        $before = $this->state();

        $this->postJson(self::API.'/students', $payload)->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')->assertJsonValidationErrors([$field], 'error.details');

        $this->assertSame($before, $this->state());
    }

    public function test_one_hundred_students_and_maximum_text_lengths_are_accepted(): void
    {
        $this->leader();
        $rows = array_fill(0, 100, ['name' => str_repeat('n', 255), 'nim' => null]);
        $rows[0]['nim'] = str_repeat('0', 32);

        $this->postJson(self::API.'/students', ['students' => $rows])->assertCreated()->assertJsonCount(100, 'data');

        $this->assertDatabaseCount('attendance_students', 100);
        $this->assertDatabaseHas('attendance_students', ['name' => str_repeat('n', 255), 'nim' => str_repeat('0', 32)]);
    }

    public function test_manual_text_is_trimmed_without_converting_nims_or_interpreting_markup(): void
    {
        $this->leader();

        $student = $this->postJson(self::API.'/students', ['students' => [[
            'name' => '  <script>alert("synthetic")</script>  ', 'nim' => '  0000123  ',
        ]]])->assertCreated()->assertJsonPath('data.0.name', '<script>alert("synthetic")</script>')
            ->assertJsonPath('data.0.nim', '0000123')->json('data.0');

        $this->assertDatabaseHas('attendance_students', [
            'id' => $student['id'], 'name' => '<script>alert("synthetic")</script>', 'nim' => '0000123', 'user_id' => null,
        ]);
        $this->patchJson(self::API.'/students/'.$student['id'], ['name' => '  Updated student  ', 'nim' => '   '])
            ->assertOk()->assertJsonPath('data.name', 'Updated student')->assertJsonPath('data.nim', null);
        $this->assertDatabaseHas('attendance_students', ['id' => $student['id'], 'name' => 'Updated student', 'nim' => null]);
    }

    public function test_duplicate_nims_are_case_insensitive_atomic_and_class_scoped_including_archived_students(): void
    {
        [$class, $leader] = $this->leader();
        $existing = $this->student($class, ['nim' => 'AbC001', 'is_active' => false]);
        $before = $this->state();

        foreach ([
            [['name' => 'Valid first', 'nim' => 'NEW'], ['name' => 'Existing collision', 'nim' => 'abc001']],
            [['name' => 'Batch first', 'nim' => 'XyZ'], ['name' => 'Batch collision', 'nim' => 'xyz']],
            [['name' => 'Exact first', 'nim' => 'DUP'], ['name' => 'Exact collision', 'nim' => 'DUP']],
        ] as $rows) {
            $this->postJson(self::API.'/students', ['students' => $rows])->assertUnprocessable();
            $this->assertSame($before, $this->state());
        }

        [$otherClass] = $this->leader();
        $other = $this->postJson(self::API.'/students', ['students' => [['name' => 'Other class', 'nim' => 'ABC001']]])
            ->assertCreated()->json('data.0');
        $this->assertDatabaseHas('attendance_students', ['id' => $other['id'], 'class_id' => $otherClass->id, 'nim' => 'ABC001']);
        $this->actingAs($leader, 'sanctum');
        $this->patchJson(self::API.'/students/'.$existing, ['name' => 'Own case edit', 'nim' => 'ABC001', 'is_active' => true])
            ->assertOk()->assertJsonPath('data.nim', 'ABC001');
        $this->assertDatabaseHas('attendance_students', ['id' => $existing, 'class_id' => $class->id, 'nim' => 'ABC001', 'is_active' => true]);
    }

    public function test_edit_returns_422_for_invalid_values_or_duplicate_nim_without_changing_the_student(): void
    {
        [$class] = $this->leader();
        $student = $this->student($class, ['nim' => '001']);
        $this->student($class, ['nim' => 'TAKEN']);
        $before = $this->state();

        foreach ([
            [['name' => ''], 'name'],
            [['name' => str_repeat('x', 256)], 'name'],
            [['nim' => str_repeat('0', 33)], 'nim'],
            [['nim' => 1234], 'nim'],
            [['nim' => 'taken'], 'nim'],
            [['is_active' => 'sometimes'], 'is_active'],
        ] as [$changes, $field]) {
            $this->patchJson(self::API.'/students/'.$student, array_replace(['name' => 'Edited student', 'nim' => '001'], $changes))
                ->assertUnprocessable()->assertJsonValidationErrors([$field], 'error.details');
            $this->assertSame($before, $this->state());
        }
    }

    public function test_class_and_legacy_user_references_cannot_be_assigned_through_manual_requests(): void
    {
        [$class, $leader] = $this->leader();
        $foreignClass = Domain::classRoom();
        $before = $this->accountState();
        $row = ['name' => 'Manual only', 'nim' => '001'];

        $this->postJson(self::API.'/students', ['class_id' => $foreignClass->id, 'user_id' => $leader->id, 'students' => [$row]])
            ->assertCreated();
        $student = DB::table('attendance_students')->sole();
        $this->assertSame($class->id, $student->class_id);
        $this->assertNull($student->user_id);

        $this->postJson(self::API.'/students', ['students' => [[
            'name' => 'Forged nested row', 'nim' => 'UNUSED', 'class_id' => $foreignClass->id, 'user_id' => $leader->id,
        ]]])->assertUnprocessable()->assertJsonValidationErrors(['students.0'], 'error.details');
        $this->patchJson(self::API.'/students/'.$student->id, [
            'name' => 'Still manual', 'nim' => '0002', 'class_id' => $foreignClass->id, 'user_id' => $leader->id,
        ])->assertOk();

        $this->assertDatabaseHas('attendance_students', [
            'id' => $student->id, 'class_id' => $class->id, 'user_id' => null, 'name' => 'Still manual', 'nim' => '0002',
        ]);
        $this->assertDatabaseCount('attendance_students', 1);
        $this->assertSame($before, $this->accountState());
    }

    public function test_archive_edit_and_reopen_change_future_rosters_but_never_historical_snapshots(): void
    {
        [$class] = $this->leader();
        $student = $this->student($class, ['name' => 'Original student', 'nim' => '0000123']);
        $remaining = $this->student($class, ['name' => 'Remaining student']);
        $course = $this->postJson(self::API.'/courses', ['name' => 'Course', 'lecturer_name' => 'Lecturer'])->assertCreated()->json('data');
        $original = $this->postJson(self::API.'/sessions', ['course_id' => $course['id'], 'held_on' => '2026-10-20'])
            ->assertCreated()->json('data');

        $this->patchJson(self::API.'/students/'.$student, ['name' => 'Renamed student', 'nim' => '0099', 'is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->getJson(self::API.'/students')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson(self::API)->assertOk()->assertJsonCount(1, 'data.roster')->assertJsonPath('data.roster.0.student_id', $remaining);
        $this->getJson(self::API.'/sessions/'.$original['id'])->assertOk()->assertJsonPath('data', $original);
        $this->postJson(self::API.'/sessions', ['course_id' => $course['id'], 'held_on' => '2026-10-21'])
            ->assertCreated()->assertJsonCount(1, 'data.records')->assertJsonPath('data.records.0.student_id', $remaining);

        $this->patchJson(self::API.'/students/'.$student, ['name' => 'Renamed student', 'nim' => null, 'is_active' => true])
            ->assertOk()->assertJsonPath('data.nim', null);
        $next = $this->postJson(self::API.'/sessions', ['course_id' => $course['id'], 'held_on' => '2026-10-22'])
            ->assertCreated()->assertJsonCount(2, 'data.records')->json('data');
        $this->assertSame('Renamed student', array_column($next['records'], null, 'student_id')[$student]['name']);
        $this->assertDatabaseHas('attendance_records', ['session_id' => $original['id'], 'student_id' => $student, 'name' => 'Original student', 'nim' => '0000123']);
        $this->deleteJson(self::API.'/students/'.$student)->assertMethodNotAllowed();
        $this->assertDatabaseHas('attendance_students', ['id' => $student, 'is_active' => true]);
    }

    public function test_active_limit_allows_exactly_one_thousand_and_checks_bulk_add_and_reactivation_atomically(): void
    {
        [$class] = $this->leader();
        DB::table('attendance_students')->insert(array_fill(0, 999, [
            'class_id' => $class->id, 'user_id' => null, 'name' => 'Capacity student', 'nim' => null,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]));
        $archived = $this->student($class, ['name' => 'Archived capacity student', 'is_active' => false]);
        $this->student(Domain::classRoom(), ['name' => 'Other class does not consume capacity']);
        $before = $this->state();

        $this->postJson(self::API.'/students', ['students' => [['name' => 'Would fit alone'], ['name' => 'Overflow']]])
            ->assertUnprocessable();
        $this->assertSame($before, $this->state());
        $last = $this->postJson(self::API.'/students', ['students' => [['name' => 'Last place']]])->assertCreated()->json('data.0');
        $before = $this->state();
        $this->postJson(self::API.'/students', ['students' => [['name' => 'Overflow']]])->assertUnprocessable();
        $this->patchJson(self::API.'/students/'.$archived, ['name' => 'Archived capacity student', 'is_active' => true])
            ->assertUnprocessable();
        $this->assertSame($before, $this->state());

        $this->patchJson(self::API.'/students/'.$last['id'], ['name' => 'Edited at capacity', 'is_active' => true])->assertOk();
        $this->patchJson(self::API.'/students/'.$last['id'], ['name' => 'Edited at capacity', 'is_active' => false])->assertOk();
        $this->patchJson(self::API.'/students/'.$archived, ['name' => 'Reopened after space freed', 'is_active' => true])->assertOk();
        $this->assertSame(1000, DB::table('attendance_students')->where('class_id', $class->id)->where('is_active', true)->count());
        $this->assertDatabaseCount('attendance_students', 1002);
        $this->assertDatabaseHas('attendance_students', ['id' => $archived, 'is_active' => true]);
    }

    private function leader(): array
    {
        $class = Domain::classRoom();
        $leader = Domain::user(UserRole::ClassLeader);
        Domain::leader($leader, $class);
        $this->actingAs($leader, 'sanctum');

        return [$class, $leader];
    }

    private function student(ClassRoom $class, array $overrides = []): int
    {
        return DB::table('attendance_students')->insertGetId(array_replace([
            'class_id' => $class->id, 'user_id' => null, 'name' => 'Manual student', 'nim' => null,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    private function accountState(): array
    {
        $state = [];
        foreach (['users', 'class_memberships', 'identity_verifications'] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $state;
    }

    private function state(): array
    {
        $state = $this->accountState();
        foreach (['attendance_students', 'attendance_courses', 'attendance_sessions', 'attendance_records', 'attendance_events', 'audit_logs'] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $state;
    }
}
