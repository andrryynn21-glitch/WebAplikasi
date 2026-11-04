<?php

namespace App\Enums;

enum AspirationStatus: string
{
    case Published = 'published';
    case Hidden = 'hidden';
    case Removed = 'removed';
    case Locked = 'locked';

    public function label(): string
    {
        return match ($this) {
            self::Published => 'Tayang',
            self::Hidden => 'Disembunyikan',
            self::Removed => 'Dihapus moderator',
            self::Locked => 'Dikunci (komentar ditutup)',
        };
    }

    public function isVisible(): bool
    {
        return $this === self::Published || $this === self::Locked;
    }
}
