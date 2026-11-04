<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\SecurityEvent;
use App\Models\User;

/**
 * Security event hanya untuk pengguna dengan permission security.view.
 * Isi data yang ditampilkan juga dibatasi oleh controller (tanpa konteks
 * sensitif/isi laporan).
 */
class SecurityEventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->hasPermission('security.view');
    }

    public function view(User $user, SecurityEvent $event): bool
    {
        return $this->viewAny($user)
            && ($user->hasRole(UserRole::SuperAdmin)
                || ($event->class_id !== null
                    && ($user->isClassLeaderOf($event->class_id) || $user->isLecturerOf($event->class_id))));
    }
}
