<?php

namespace App\Enums;

enum ReportSensitivity: string
{
    case Routine = 'routine';
    case Sensitive = 'sensitive';

    public function label(): string
    {
        return match ($this) {
            self::Routine => 'Rutin',
            self::Sensitive => 'Sensitif',
        };
    }
}
