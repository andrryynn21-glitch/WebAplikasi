<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\NotificationType;
use App\Enums\UserRole;
use App\Models\ClassAdministratorAssignment;
use App\Models\ClassRoom;
use App\Models\LecturerAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Pengelolaan kelas, penugasan dosen, dan ketua kelas.
 * Otorisasi dilakukan pemanggil (policy/middleware); service menjaga
 * integritas data.
 */
class ClassroomService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly NotificationService $notifications,
    ) {}

    public function create(array $attributes, User $actor): ClassRoom
    {
        return DB::transaction(function () use ($attributes, $actor) {
            $class = ClassRoom::create(array_merge($attributes, [
                'join_code' => $attributes['join_code'] ?? $this->generateJoinCode(),
                'created_by' => $actor->id,
            ]));

            $this->audit->record(AuditAction::ClassCreated, $actor, $class, $class->id, [
                'code' => $class->code,
                'name' => $class->name,
            ]);

            return $class;
        });
    }

    public function update(ClassRoom $class, array $attributes, User $actor): ClassRoom
    {
        return DB::transaction(function () use ($class, $attributes, $actor) {
            $before = $class->only(['code', 'name', 'program_study', 'semester', 'is_active']);
            $class->update($attributes);

            $this->audit->record(AuditAction::ClassUpdated, $actor, $class, $class->id, [
                'before' => $before,
                'after' => $class->only(['code', 'name', 'program_study', 'semester', 'is_active']),
            ]);

            return $class;
        });
    }

    public function assignLecturer(ClassRoom $class, User $lecturer, User $actor, string $role = 'coordinator'): LecturerAssignment
    {
        if (! $lecturer->is_active || ! $lecturer->hasRole(UserRole::LecturerCoordinator)) {
            throw ValidationException::withMessages(['lecturer_id' => 'Pengguna tujuan harus dosen koordinator aktif.']);
        }

        return DB::transaction(function () use ($class, $lecturer, $actor, $role) {
            $assignment = $class->lecturerAssignments()->create([
                'lecturer_id' => $lecturer->id,
                'assigned_by' => $actor->id,
                'role' => $role,
                'starts_at' => now(),
            ]);

            $this->audit->record(AuditAction::LecturerAssigned, $actor, $assignment, $class->id, [
                'lecturer_id' => $lecturer->id,
                'role' => $role,
            ]);

            return $assignment;
        });
    }

    public function removeLecturer(ClassRoom $class, LecturerAssignment $assignment, User $actor): void
    {
        DB::transaction(function () use ($class, $assignment, $actor) {
            $assignment->forceFill(['ends_at' => now()])->save();

            $this->audit->record(AuditAction::LecturerUnassigned, $actor, $assignment, $class->id, [
                'lecturer_id' => $assignment->lecturer_id,
            ]);
        });
    }

    /**
     * Tetapkan ketua kelas aktif. Satu kelas hanya boleh memiliki satu
     * ketua aktif; penugasan lama diakhiri dalam transaksi yang sama dan
     * unique index active_class_id menjaga konsistensi di level database.
     */
    public function assignClassLeader(ClassRoom $class, User $leader, User $actor): ClassAdministratorAssignment
    {
        Gate::forUser($actor)->authorize('assignLeader', $class);
        if (! $leader->hasRole(UserRole::ClassLeader, UserRole::Student)) {
            throw ValidationException::withMessages(['user_id' => 'Ketua kelas harus berstatus mahasiswa atau ketua kelas.']);
        }

        return DB::transaction(function () use ($class, $leader, $actor) {
            ClassRoom::query()->lockForUpdate()->findOrFail($class->id);
            $leader = User::query()->lockForUpdate()->findOrFail($leader->id);
            if (! $leader->canUseClassFeatures() || $leader->activeClassId() !== $class->id) {
                throw ValidationException::withMessages([
                    'user_id' => 'Ketua kelas harus terverifikasi dan menjadi anggota aktif kelas ini.',
                ]);
            }
            $current = ClassAdministratorAssignment::query()
                ->where('class_id', $class->id)
                ->whereNull('ends_at')
                ->lockForUpdate()
                ->first();

            if ($current !== null && $current->user_id === $leader->id) {
                return $current;
            }

            if ($current !== null) {
                $current->forceFill([
                    'ends_at' => now(),
                    'revocation_reason' => 'Digantikan oleh ketua kelas baru.',
                ])->save();
            }

            $assignment = ClassAdministratorAssignment::create([
                'user_id' => $leader->id,
                'class_id' => $class->id,
                'assigned_by' => $actor->id,
                'starts_at' => now(),
            ]);

            // Ketua kelas perlu role agar permission kelas berlaku.
            if ($leader->roleKey() === UserRole::Student) {
                (new RoleAssignmentService($this->audit))->assign($leader, UserRole::ClassLeader, $actor, 'Ditetapkan sebagai ketua kelas.');
            }

            $this->audit->record(AuditAction::ClassLeaderAssigned, $actor, $assignment, $class->id, [
                'leader_id' => $leader->id,
                'replaced' => $current?->user_id,
            ]);

            $this->notifications->send(
                $leader,
                NotificationType::MembershipDecision,
                'Anda ditetapkan sebagai ketua kelas',
                'Kewenangan ketua kelas aktif untuk '.$class->name.'.',
                ['class_id' => $class->id],
            );

            return $assignment;
        });
    }

    public function revokeClassLeader(ClassAdministratorAssignment $assignment, User $actor, string $reason): void
    {
        DB::transaction(function () use ($assignment, $actor, $reason) {
            $assignment->forceFill([
                'ends_at' => now(),
                'revocation_reason' => $reason,
            ])->save();

            $this->audit->record(AuditAction::ClassLeaderRevoked, $actor, $assignment, $assignment->class_id, [
                'leader_id' => $assignment->user_id,
                'reason' => $reason,
            ]);
        });
    }

    public function generateJoinCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (ClassRoom::query()->where('join_code', $code)->exists());

        return $code;
    }
}
