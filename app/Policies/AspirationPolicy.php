<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Aspiration;
use App\Models\User;

class AspirationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canUseClassFeatures()
            || ($user->is_active && $user->hasRole(UserRole::LecturerCoordinator)
                && $user->accessibleClassIds() !== []);
    }

    public function view(User $user, Aspiration $aspiration): bool
    {
        if (! (($user->canUseClassFeatures() && $aspiration->class_id === $user->activeClassId())
            || $user->isLecturerOf($aspiration->class_id))) {
            return false;
        }

        if ($aspiration->isVisible()) {
            return true;
        }

        return $aspiration->author_id === $user->id
            || $this->moderate($user, $aspiration);
    }

    public function create(User $user): bool
    {
        return $user->canUseClassFeatures() && $user->hasPermission('aspirations.create');
    }

    public function comment(User $user, Aspiration $aspiration): bool
    {
        return $user->canUseClassFeatures()
            && $user->hasPermission('aspirations.participate')
            && $aspiration->class_id === $user->activeClassId()
            && $aspiration->acceptsComments();
    }

    public function support(User $user, Aspiration $aspiration): bool
    {
        return $user->canUseClassFeatures()
            && $user->hasPermission('aspirations.participate')
            && $aspiration->class_id === $user->activeClassId()
            && $aspiration->isVisible();
    }

    public function flag(User $user, Aspiration $aspiration): bool
    {
        return $user->canUseClassFeatures()
            && $user->hasPermission('aspirations.flag')
            && $aspiration->class_id === $user->activeClassId()
            && $aspiration->isVisible()
            && $aspiration->author_id !== $user->id;
    }

    public function moderate(User $user, Aspiration $aspiration): bool
    {
        return $aspiration->author_id !== $user->id && $this->viewFlags($user, $aspiration);
    }

    public function viewFlags(User $user, Aspiration $aspiration): bool
    {
        return $user->is_active
            && $user->hasPermission('aspirations.moderate')
            && ($user->isClassLeaderOf($aspiration->class_id)
                || $user->isLecturerOf($aspiration->class_id));
    }

    public function update(User $user, Aspiration $aspiration): bool
    {
        return $user->canUseClassFeatures()
            && $aspiration->author_id === $user->id
            && $aspiration->isVisible()
            && $aspiration->class_id === $user->activeClassId();
    }
}
