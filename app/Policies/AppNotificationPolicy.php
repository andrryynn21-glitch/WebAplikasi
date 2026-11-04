<?php

namespace App\Policies;

use App\Models\AppNotification;
use App\Models\User;

/**
 * Notifikasi in-app hanya dapat dibaca oleh pemiliknya.
 */
class AppNotificationPolicy
{
    public function view(User $user, AppNotification $notification): bool
    {
        return $notification->isVisibleTo($user);
    }

    public function update(User $user, AppNotification $notification): bool
    {
        return $this->view($user, $notification);
    }

    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }
}
