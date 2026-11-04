<?php

namespace App\Enums;

enum ContentFlagStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Menunggu moderasi',
            self::Resolved => 'Ditindak',
            self::Dismissed => 'Diabaikan',
        };
    }
}
