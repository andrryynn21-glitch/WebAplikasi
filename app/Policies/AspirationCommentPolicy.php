<?php

namespace App\Policies;

use App\Models\AspirationComment;
use App\Models\User;

class AspirationCommentPolicy
{
    public function view(User $user, AspirationComment $comment): bool
    {
        $aspiration = $comment->aspiration;

        if ($aspiration === null || ! $user->can('view', $aspiration)) {
            return false;
        }

        return $comment->isVisible()
            || $comment->author_id === $user->id
            || $this->moderate($user, $comment);
    }

    public function flag(User $user, AspirationComment $comment): bool
    {
        return $user->canUseClassFeatures()
            && $user->hasPermission('aspirations.flag')
            && $comment->aspiration->class_id === $user->activeClassId()
            && $comment->aspiration->isVisible()
            && $comment->isVisible()
            && $comment->author_id !== $user->id;
    }

    public function moderate(User $user, AspirationComment $comment): bool
    {
        return $user->is_active
            && $user->hasPermission('aspirations.moderate')
            && $comment->author_id !== $user->id
            && ($user->isClassLeaderOf($comment->aspiration->class_id)
                || $user->isLecturerOf($comment->aspiration->class_id));
    }
}
