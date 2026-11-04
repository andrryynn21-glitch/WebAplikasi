<?php

namespace App\Enums;

enum AppealStatus: string
{
    case Pending = 'pending';
    case UnderReview = 'under_review';
    case Accepted = 'accepted';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Menunggu peninjauan',
            self::UnderReview => 'Sedang ditinjau',
            self::Accepted => 'Diterima',
            self::Rejected => 'Ditolak',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::UnderReview;
    }
}
