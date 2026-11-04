<?php

namespace App\Policies;

use App\Models\IdentityVerification;
use App\Models\User;

/**
 * KTM hanya dapat dilihat pemiliknya dan petugas verifikasi.
 * Super Admin tidak memilik akses otomatis ke dokumen identitas.
 */
class IdentityVerificationPolicy
{
    public function view(User $user, IdentityVerification $verification): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->id === $verification->user_id) {
            return true;
        }

        return $user->is_active && $user->hasPermission('identity.verify');
    }

    public function download(User $user, IdentityVerification $verification): bool
    {
        return $this->view($user, $verification);
    }

    public function review(User $user, IdentityVerification $verification): bool
    {
        return $user->is_active
            && $user->hasPermission('identity.verify')
            && $user->id !== $verification->user_id
            && $verification->user?->is_active;
    }

    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->hasPermission('identity.verify');
    }
}
