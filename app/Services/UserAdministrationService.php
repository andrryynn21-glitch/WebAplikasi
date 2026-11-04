<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserAdministrationService
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function suspend(User $target, User $actor, string $reason): void
    {
        DB::transaction(function () use ($target, $actor, $reason): void {
            $adminRole = app(UserAccessLock::class)->lock();
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            if (! $actor->is_active || ! $actor->hasPermission('users.manage') || $actor->id === $target->id) {
                throw new AuthorizationException('Penonaktifan akun tidak diizinkan.');
            }

            $target = User::query()->lockForUpdate()->findOrFail($target->id);
            if ($target->is_active && $target->role_id === $adminRole->id
                && User::query()->where('role_id', $adminRole->id)->where('is_active', true)->count() <= 1) {
                throw ValidationException::withMessages(['user' => 'Super Admin aktif terakhir tidak dapat dinonaktifkan.']);
            }

            $target->forceFill(['is_active' => false, 'suspended_at' => now(), 'suspension_reason' => $reason])->save();
            $target->tokens()->delete();
            $this->audit->record(AuditAction::UserSuspended, $actor, $target);
        });
    }

    public function reactivate(User $target, User $actor): void
    {
        DB::transaction(function () use ($target, $actor): void {
            app(UserAccessLock::class)->lock();
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            if (! $actor->is_active || ! $actor->hasPermission('users.manage')) {
                throw new AuthorizationException('Aktivasi akun tidak diizinkan.');
            }
            $target = User::query()->lockForUpdate()->findOrFail($target->id);
            $target->forceFill(['is_active' => true, 'suspended_at' => null, 'suspension_reason' => null])->save();
            $this->audit->record(AuditAction::UserReactivated, $actor, $target);
        });
    }
}
