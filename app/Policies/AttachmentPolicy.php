<?php

namespace App\Policies;

use App\Enums\AttachmentType;
use App\Models\Aspiration;
use App\Models\Attachment;
use App\Models\Report;
use App\Models\User;

/**
 * Akses file privat. Lampiran tidak pernah punya URL publik; setiap
 * unduhan melewati endpoint ber-otorisasi dan policy ini.
 */
class AttachmentPolicy
{
    public function download(User $user, Attachment $attachment): bool
    {
        if (! $user->is_active) {
            return false;
        }

        $parent = $attachment->attachable;

        if ($parent instanceof Report) {
            return $this->canViewReportFile($user, $parent);
        }

        if ($parent instanceof Aspiration) {
            return $attachment->type === AttachmentType::AspirationImage
                && $user->can('view', $parent);
        }

        return false;
    }

    public function delete(User $user, Attachment $attachment): bool
    {
        if (! $this->download($user, $attachment)) {
            return false;
        }
        $parent = $attachment->attachable;

        if ($attachment->uploaded_by === $user->id) {
            return true;
        }

        return $parent instanceof Report && $this->canViewReportFile($user, $parent);
    }

    private function canViewReportFile(User $user, Report $report): bool
    {
        return $user->can('viewPrivateDetails', $report);
    }
}
