<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Models\Role;
use App\Models\RoleChangeRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Satu-satunya jalur perubahan role. Setiap perubahan dicatat ke audit log.
 * Registrasi publik tidak dapat memanggil service ini; hanya pengguna
 * berwenang (super admin) melalui endpoint khusus.
 */
class RoleAssignmentService
{
    public function __construct(
        private readonly AuditLogService $audit,
    ) {}

    public function assign(User $user, UserRole $role, User $actor, ?string $reason = null): void
    {
        if (! $actor->is_active || ! $actor->hasPermission('roles.manage') || $user->id === $actor->id) {
            throw new AuthorizationException('Perubahan role tidak diizinkan.');
        }

        DB::transaction(function () use ($user, $role, $actor, $reason) {
            $superAdminRole = app(UserAccessLock::class)->lock();
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            if (! $actor->is_active || ! $actor->hasPermission('roles.manage')) {
                throw new AuthorizationException('Perubahan role tidak diizinkan.');
            }
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            $previous = $user->roleKey();

            if ($user->is_active && $user->role_id === $superAdminRole->id && $role !== UserRole::SuperAdmin
                && User::query()->where('role_id', $superAdminRole->id)->where('is_active', true)->count() <= 1) {
                throw ValidationException::withMessages(['role' => 'Super Admin aktif terakhir tidak dapat diturunkan role-nya.']);
            }

            $roleModel = Role::query()->where('key', $role->value)->firstOrFail();

            $user->forceFill(['role_id' => $roleModel->id])->save();
            $user->unsetRelation('role');

            $this->audit->record(AuditAction::RoleAssigned, $actor, $user, $user->activeClassId(), [
                'from' => $previous?->value,
                'to' => $role->value,
                'reason' => $reason,
            ]);
        });
    }

    public function requestChange(User $target, UserRole $to, User $requester, string $reason): RoleChangeRequest
    {
        return DB::transaction(function () use ($target, $to, $requester, $reason) {
            return RoleChangeRequest::create([
                'user_id' => $target->id,
                'requested_by' => $requester->id,
                'from_role' => $target->roleKey()?->value,
                'to_role' => $to->value,
                'reason' => $reason,
                'status' => 'pending',
            ]);
        });
    }
}
