<?php

namespace App\Policies;

use App\Models\User;

/**
 * Akun/administrasi. Semua tindakan manajemen pengguna memerlukan
 * permission users.manage; Super Admin tidak mendapat pengecualian
 * untuk data pengaduan di sini.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active
            && $user->hasPermission('users.manage');
    }

    public function view(User $user, User $target): bool
    {
        return $user->is_active
            && ($user->id === $target->id || $user->hasPermission('users.manage'));
    }

    public function update(User $user, User $target): bool
    {
        return $user->is_active && $user->hasPermission('users.manage');
    }
}
