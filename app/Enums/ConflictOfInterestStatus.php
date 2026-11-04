<?php

namespace App\Enums;

enum ConflictOfInterestStatus: string
{
    case Declared = 'declared';
    case Cleared = 'cleared';
    case Confirmed = 'confirmed';

    public function label(): string
    {
        return match ($this) {
            self::Declared => 'Dinyatakan',
            self::Cleared => 'Tidak terbukti',
            self::Confirmed => 'Terbukti',
        };
    }

    public function blocksHandling(): bool
    {
        return $this !== self::Cleared;
    }
}
