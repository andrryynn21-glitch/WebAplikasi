<?php

namespace App\Enums;

enum AssignmentType: string
{
    case Primary = 'primary';
    case Secondary = 'secondary';
    case AppealReviewer = 'appeal_reviewer';

    public function label(): string
    {
        return match ($this) {
            self::Primary => 'Penangan utama',
            self::Secondary => 'Pendamping / peninjau',
            self::AppealReviewer => 'Peninjau banding',
        };
    }
}
