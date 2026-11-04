<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Services\AttendanceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Domain;
use Tests\TestCase;

class AttendanceAccessConcurrencyTest extends TestCase
{
    // The competing connection must see committed fixtures, not RefreshDatabase's transaction.
    use DatabaseMigrations;

    public static function revokedAccess(): array
    {
        return [
            'current leader assignment ended' => ['class_administrator_assignments', 'ends_at', null, '2026-11-03 10:00:00'],
            'active membership revoked' => ['class_memberships', 'status', 'active', 'revoked'],
        ];
    }

    #[DataProvider('revokedAccess')]
    public function test_course_save_is_forbidden_when_access_is_revoked_after_its_read_snapshot(
        string $table,
        string $column,
        ?string $previous,
        string $revoked,
    ): void {
        $this->travelTo(Carbon::parse('2026-11-03 10:00:00', 'Asia/Jakarta'));
        $primary = DB::connection('mysql');
        $this->assertSame(0, $primary->transactionLevel());
        $originalIsolation = $primary->selectOne(
            "SHOW SESSION VARIABLES WHERE Variable_name IN ('transaction_isolation', 'tx_isolation')"
        )->Value;
        $this->assertContains($originalIsolation, ['READ-UNCOMMITTED', 'READ-COMMITTED', 'REPEATABLE-READ', 'SERIALIZABLE']);
        $connections = config('database.connections');
        $connectionName = 'attendance_access_concurrent';
        $this->assertArrayNotHasKey($connectionName, $connections);
        config(['database.connections.'.$connectionName => $connections['mysql']]);
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
            $leader = Domain::user(UserRole::ClassLeader);
            Domain::leader($leader, $class)->update(['starts_at' => now()->subDay()]);
            Gate::forUser($leader)->authorize('manageAttendance', $class);
            $target = ['class_id' => $class->id, 'user_id' => $leader->id];
            $this->assertDatabaseHas($table, $target + [$column => $previous], $connectionName);

            $armed = true;
            $primary->beforeExecuting(function (string $sql, array $bindings, Connection $connection) use (
                &$armed, &$fired, $primary, $secondary, $table, $column, $target, $previous, $revoked,
            ): void {
                $sql = strtolower($sql);
                if (! $armed || $connection !== $primary || ! str_starts_with($sql, 'select ')
                    || ! str_contains($sql, 'from `classes`') || ! str_contains($sql, 'for update')) {
                    return;
                }
                $armed = false;
                $fired = true;
                $this->assertSame(1, $primary->transactionLevel());
                $this->assertSame(0, $secondary->transactionLevel());

                // activeClassId() has read; commit the revocation before classFor() acquires its first lock.
                $changed = $secondary->transaction(fn () => $secondary->table($table)
                    ->where($target)->where($column, $previous)->update([$column => $revoked]));
                $this->assertSame(1, $changed);
                $this->assertSame(0, $secondary->transactionLevel());

                // Prove the real MVCC gap: Gate's ordinary reads stay stale, locking reads do not.
                $this->assertSame($previous, $primary->table($table)->where($target)->value($column));
                $this->assertSame($revoked, $primary->table($table)->where($target)->lockForUpdate()->value($column));
            });

            $denial = null;
            try {
                app(AttendanceService::class)->saveCourse($leader, [
                    'code' => 'RACE101', 'name' => 'Must not be saved', 'lecturer_name' => 'Test lecturer', 'is_active' => true,
                ]);
            } catch (AuthorizationException $exception) {
                $denial = $exception;
            }

            $this->assertTrue($fired, 'The competing revocation must run at the first class locking read.');
            $this->assertSame(0, $primary->transactionLevel());
            $this->assertDatabaseHas($table, $target + [$column => $revoked], $connectionName);
            $this->assertInstanceOf(AuthorizationException::class, $denial);
            foreach (['attendance_courses', 'attendance_sessions', 'attendance_records', 'attendance_events', 'audit_logs'] as $attendanceTable) {
                $this->assertDatabaseCount($attendanceTable, 0, $connectionName);
            }
        } finally {
            // beforeExecuting is connection-scoped; leave its callback inert through migration teardown.
            $armed = false;
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
