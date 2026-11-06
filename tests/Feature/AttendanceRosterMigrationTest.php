<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ClassRoom;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\Domain;
use Tests\TestCase;

class AttendanceRosterMigrationTest extends TestCase
{
    // MySQL DDL commits implicitly; never nest this regression in RefreshDatabase transactions.
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-03 10:00:00', 'Asia/Jakarta'));
        Domain::seedCatalog();
    }

    public function test_upgrade_backfills_only_historical_class_user_pairs_and_preserves_every_snapshot(): void
    {
        $this->assertSame(0, DB::connection()->transactionLevel());
        $migration = $this->migration();
        $migration->down();
        $this->assertFalse(Schema::hasTable('attendance_students'));
        $class = Domain::classRoom(['semester' => 5]);
        $otherClass = Domain::classRoom(['semester' => 8]);
        $historicalUser = Domain::user(UserRole::Student, ['name' => 'Current profile is not a snapshot', 'nim' => 'PROFILE']);
        $duplicateNimUser = Domain::user(UserRole::Student);
        $neverAttended = Domain::user(UserRole::Student);
        Domain::member($neverAttended, $class);
        Domain::member($historicalUser, $class)->update(['status' => 'revoked']);
        $historicalUser->forceFill(['is_active' => false])->save();
        $first = $this->legacySession($class, 'FIRST');
        $later = $this->legacySession($class, 'LATER');
        $foreign = $this->legacySession($otherClass, 'FOREIGN');
        foreach ([
            [$first, $historicalUser->id, 'Original student', '000001', 'excused', 'Original explanation'],
            [$later, $historicalUser->id, 'Later snapshot name', '000002', 'sick', 'Later explanation'],
            [$later, $duplicateNimUser->id, 'Legacy duplicate NIM', '000002', 'absent', null],
            [$foreign, $historicalUser->id, 'Other class snapshot', null, 'present', 'Other class explanation'],
        ] as [$session, $user, $name, $nim, $status, $note]) {
            DB::table('attendance_records')->insert([
                'session_id' => $session, 'user_id' => $user, 'name' => $name, 'nim' => $nim,
                'status' => $status, 'note' => $note, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('attendance_events')->insert([
            'session_id' => $first, 'actor_id' => $historicalUser->id,
            'action' => 'attendance_finalized', 'reason' => null, 'created_at' => now(),
        ]);
        $before = [];
        foreach (['attendance_records', 'attendance_sessions', 'attendance_courses', 'attendance_events', 'audit_logs'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        $migration->up();

        $this->assertDatabaseCount('attendance_students', 3);
        $this->assertDatabaseMissing('attendance_students', ['user_id' => $neverAttended->id]);
        $this->assertDatabaseHas('attendance_students', [
            'class_id' => $class->id, 'user_id' => $historicalUser->id,
            'name' => 'Later snapshot name', 'nim' => '000002', 'is_active' => true,
        ]);
        $this->assertSame(2, DB::table('attendance_students')->where('class_id', $class->id)->where('nim', '000002')->count());
        foreach ($before as $table => $rows) {
            $columns = $rows === [] ? ['*'] : array_keys($rows[0]);
            $this->assertSame($rows, DB::table($table)->select($columns)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(), $table);
        }
        foreach (DB::table('attendance_records')->get() as $record) {
            $classId = DB::table('attendance_sessions')->join('attendance_courses', 'attendance_courses.id', '=', 'attendance_sessions.course_id')
                ->where('attendance_sessions.id', $record->session_id)->value('class_id');
            $this->assertNotNull($record->student_id);
            $this->assertDatabaseHas('attendance_students', ['id' => $record->student_id, 'class_id' => $classId, 'user_id' => $record->user_id]);
        }
        $firstStudent = DB::table('attendance_records')->where('session_id', $first)->value('student_id');
        $laterStudent = DB::table('attendance_records')->where('session_id', $later)->where('user_id', $historicalUser->id)->value('student_id');
        $this->assertSame($firstStudent, $laterStudent);
        $this->assertNotSame($firstStudent, DB::table('attendance_records')->where('session_id', $foreign)->value('student_id'));
        foreach ([$first => 5, $later => 5, $foreign => 8] as $session => $semester) {
            $this->assertDatabaseHas('attendance_sessions', ['id' => $session, 'semester' => $semester]);
        }
        $this->assertDatabaseHas('attendance_courses', ['class_id' => $class->id, 'semester' => 5]);
        $this->assertDatabaseHas('attendance_courses', ['class_id' => $otherClass->id, 'semester' => 8]);
    }

    public static function manualData(): array
    {
        return ['unused manual roster' => [false], 'manual attendance record' => [true]];
    }

    #[DataProvider('manualData')]
    public function test_rollback_refuses_to_destroy_manual_students_or_null_user_records(bool $withRecord): void
    {
        $class = Domain::classRoom();
        $student = DB::table('attendance_students')->insertGetId([
            'class_id' => $class->id, 'user_id' => null, 'name' => 'Manual student', 'nim' => '000123',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($withRecord) {
            $session = $this->legacySession($class, 'MANUAL', true);
            DB::table('attendance_records')->insert([
                'session_id' => $session, 'student_id' => $student, 'user_id' => null,
                'name' => 'Manual snapshot', 'nim' => '000123', 'status' => 'sick', 'note' => 'Preserve me',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        try {
            $this->assertThrows(fn () => $this->migration()->down(), RuntimeException::class);

            $this->assertTrue(Schema::hasColumn('attendance_records', 'student_id'));
            $this->assertTrue(Schema::hasColumn('attendance_courses', 'semester'));
            $this->assertTrue(Schema::hasColumn('attendance_sessions', 'semester'));
            $this->assertDatabaseHas('attendance_students', ['id' => $student, 'user_id' => null, 'nim' => '000123']);
            if ($withRecord) {
                $this->assertDatabaseHas('attendance_records', [
                    'student_id' => $student, 'user_id' => null, 'name' => 'Manual snapshot', 'status' => 'sick', 'note' => 'Preserve me',
                ]);
            }
        } finally {
            // Remove only these isolated fixtures so DatabaseMigrations can perform its normal rollback.
            if (Schema::hasColumn('attendance_records', 'student_id')) {
                DB::table('attendance_records')->where('student_id', $student)->delete();
            }
            if (Schema::hasTable('attendance_students')) {
                DB::table('attendance_students')->where('id', $student)->delete();
            }
        }
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_06_144857_add_manual_attendance_roster.php');
    }

    private function legacySession(ClassRoom $class, string $code, bool $withSemester = false): int
    {
        $course = DB::table('attendance_courses')->insertGetId([
            'class_id' => $class->id, 'code' => $code, 'name' => 'Course '.$code, 'lecturer_name' => 'Catalog lecturer',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ...($withSemester ? ['semester' => $class->semester] : []),
        ]);

        return DB::table('attendance_sessions')->insertGetId([
            'course_id' => $course, 'meeting_number' => 1, 'held_on' => '2026-10-20',
            'course_code' => $code, 'course_name' => 'Historical course '.$code, 'lecturer_name' => 'Historical lecturer',
            'status' => 'finalized', 'version' => 7, 'finalized_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ...($withSemester ? ['semester' => $class->semester] : []),
        ]);
    }
}
