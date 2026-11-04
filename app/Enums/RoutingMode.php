<?php

namespace App\Enums;

enum RoutingMode: string
{
    case Auto = 'auto';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Auto => 'Dirutekan otomatis',
            self::Manual => 'Perlu peninjauan manusia',
        };
    }
}
