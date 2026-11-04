<?php

namespace App\Policies;

use App\Models\ClassMembership;
use App\Models\ClassRoom;
use App\Models\User;

class ClassRoomPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, ClassRoom $class): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->hasPermission('classes.manage')) {
            return true;
        }

        return in_array($class->id, $user->accessibleClassIds(), true);
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->hasPermission('classes.manage');
    }

    public function update(User $user, ClassRoom $class): bool
    {
        return $user->is_active && $user->hasPermission('classes.manage');
    }

    public function manageMemberships(User $user, ClassRoom $class): bool
    {
        if (! $user->is_active || ! $user->hasPermission('memberships.review')) {
            return false;
        }

        // Ketua kelas hanya mengelola kelasnya; dosen hanya kelas yang ditugaskan.
        return $user->isClassLeaderOf($class->id)
            || $user->isLecturerOf($class->id)
            || $user->hasPermission('classes.manage');
    }

    public function manageInvitations(User $user, ClassRoom $class): bool
    {
        return $user->is_active
            && $user->hasPermission('classes.invite')
            && ($user->isClassLeaderOf($class->id)
                || $user->isLecturerOf($class->id)
                || $user->hasPermission('classes.manage'));
    }

    public function manageAttendance(User $user, ClassRoom $class): bool
    {
        return $class->is_active
            && $user->hasPermission('attendance.manage')
            && $user->isClassLeaderOf($class->id);
    }

    public function assignLeader(User $user, ClassRoom $class): bool
    {
        // Penetapan ketua kelas adalah wewenang administratif.
        return $user->is_active && $user->hasPermission('classes.manage');
    }

    public function assignLecturer(User $user, ClassRoom $class): bool
    {
        return $user->is_active && $user->hasPermission('classes.manage');
    }

    public function review(User $user, ClassMembership $membership): bool
    {
        return $membership->user_id !== $user->id
            && $this->manageMemberships($user, $membership->classRoom);
    }
}
