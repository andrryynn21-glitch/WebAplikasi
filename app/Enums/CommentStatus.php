<?php

namespace App\Enums;

enum CommentStatus: string
{
    case Published = 'published';
    case Hidden = 'hidden';
    case Removed = 'removed';

    public function label(): string
    {
        return match ($this) {
            self::Published => 'Tayang',
            self::Hidden => 'Disembunyikan',
            self::Removed => 'Dihapus moderator',
        };
    }

    public function isVisible(): bool
    {
        return $this === self::Published;
    }
}
