<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Services\AttendanceService;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Domain;
use Tests\TestCase;

class AttendanceRosterConcurrencyTest extends TestCase
{
    // The competing connection must see committed fixtures, not RefreshDatabase's transaction.
    use DatabaseMigrations;

    public static function concurrentRosterChanges(): array
    {
        return [
            'addStudents rejects a concurrently inserted NIM' => [false, 'students.0.nim'],
            'create rejects the concurrently archived last student' => [true, 'roster'],
        ];
    }

    #[DataProvider('concurrentRosterChanges')]
    public function test_roster_write_rejects_with_422_after_a_change_commits_beyond_its_read_snapshot(bool $archive, string $field): void
    {
        $this->travelTo(Carbon::parse('2026-11-03 10:00:00', 'Asia/Jakarta'));
        $primary = DB::connection('mysql');
        $this->assertSame(0, $primary->transactionLevel());
        $originalIsolation = $primary->selectOne(
            "SHOW SESSION VARIABLES WHERE Variable_name IN ('transaction_isolation', 'tx_isolation')"
        )->Value;
        $this->assertContains($originalIsolation, ['READ-UNCOMMITTED', 'READ-COMMITTED', 'REPEATABLE-READ', 'SERIALIZABLE']);
        $connections = config('database.connections');
        $connectionName = 'attendance_roster_concurrent';
        $this->assertArrayNotHasKey($connectionName, $connections);
        config(['database.connections.'.$connectionName => $connections['mysql']]);
        $classId = null;
        $armed = false;
        $fired = false;

        try {
            if ($originalIsolation !== 'REPEATABLE-READ') {
                $primary->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }
            $secondary = DB::connection($connectionName);
            $this->assertSame('pengaduan_unpam_test', $secondary->selectOne('SELECT DATABASE() AS name')->name);
            $this->assertNotSame(
                $primary->selectOne('SELECT CONNECTION_ID() AS id')->id,
                $secondary->selectOne('SELECT CONNECTION_ID() AS id')->id,
            );

            Domain::seedCatalog();
            $class = Domain::classRoom();
            $classId = $class->id;
            $leader = Domain::user(UserRole::ClassLeader);
            Domain::leader($leader, $class)->update(['starts_at' => now()->subDay()]);
            Gate::forUser($leader)->authorize('manageAttendance', $class);
            $target = ['class_id' => $classId, 'nim' => '000123'];
            $student = $target + [
                'user_id' => null, 'name' => 'Concurrent manual student', 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ];
            if ($archive) {
                $primary->table('attendance_students')->insert($student);
            }
            $this->assertDatabaseCount('attendance_students', $archive ? 1 : 0, $connectionName);

            $armed = true;
            $primary->beforeExecuting(function (string $sql, array $bindings, Connection $connection) use (
                &$armed, &$fired, $primary, $secondary, $classId, $archive, $target, $student,
            ): void {
                $sql = strtolower($sql);
                if (! $armed || $connection !== $primary || ! str_starts_with($sql, 'select ')
                    || ! str_contains($sql, 'from `classes`') || ! str_contains($sql, 'for update')
                    || $bindings !== [$classId]) {
                    return;
                }
                $armed = false;
                $fired = true;
                $this->assertSame(1, $primary->transactionLevel());
                $this->assertSame(0, $secondary->transactionLevel());

                // activeClassId() has established the snapshot, but the class lock is not acquired yet.
                if ($archive) {
                    $changed = $secondary->transaction(fn () => $secondary->table('attendance_students')
                        ->where($target)->where('is_active', true)->update(['is_active' => false]));
                    $this->assertSame(1, $changed);
                } else {
                    $this->assertTrue($secondary->transaction(fn () => $secondary->table('attendance_students')->insert($student)));
                }
                $this->assertSame(0, $secondary->transactionLevel());

                // Prove the stale ordinary snapshot; leave locking reads and conflict retries to the service.
                $this->assertSame($archive ? 1 : 0, $primary->table('attendance_students')
                    ->where($target)->where('is_active', true)->count());
            });

            $denial = null;
            try {
                if ($archive) {
                    app(AttendanceService::class)->create($leader, [
                        'course' => ['code' => 'RACE101', 'name' => 'Must not be saved', 'lecturer_name' => 'Test lecturer', 'is_active' => true],
                        'held_on' => '2026-11-03',
                    ]);
                } else {
                    app(AttendanceService::class)->addStudents($leader, [['name' => 'Duplicate attempt', 'nim' => '000123']]);
                }
            } catch (ValidationException $exception) {
                $denial = $exception;
            }

            $this->assertTrue($fired, 'The competing roster change must run at the first locking read for this class.');
            $this->assertSame(0, $primary->transactionLevel());
            $this->assertInstanceOf(ValidationException::class, $denial);
            $this->assertSame(422, $denial->status);
            $this->assertSame([$field], array_keys($denial->errors()));
            $this->assertDatabaseCount('attendance_students', 1, $connectionName);
            $this->assertDatabaseHas('attendance_students', $target + [
                'user_id' => null, 'name' => 'Concurrent manual student', 'is_active' => ! $archive,
            ], $connectionName);
            foreach (['attendance_courses', 'attendance_sessions', 'attendance_records', 'attendance_events', 'audit_logs'] as $table) {
                $this->assertDatabaseCount($table, 0, $connectionName);
            }
        } finally {
            // Leave the connection-scoped hook inert, including during migration teardown.
            $armed = false;
            try {
                if ($classId !== null) {
                    // Remove only this class's manual fixtures, even if a regression created snapshots.
                    $students = $primary->table('attendance_students')->where('class_id', $classId)->whereNull('user_id');
                    $primary->table('attendance_records')->whereIn('student_id', (clone $students)->select('id'))->delete();
                    $students->delete();
                }
            } finally {
                try {
                    if ($originalIsolation !== 'REPEATABLE-READ') {
                        $primary->statement('SET SESSION TRANSACTION ISOLATION LEVEL '.str_replace('-', ' ', $originalIsolation));
                    }
                } finally {
                    DB::purge($connectionName);
                    config(['database.connections' => $connections]);
                }
            }
        }
    }
}
