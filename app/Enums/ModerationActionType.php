<?php

namespace App\Enums;

enum ModerationActionType: string
{
    case Hide = 'hide';
    case Restore = 'restore';
    case Remove = 'remove';
    case Lock = 'lock';
    case DismissFlag = 'dismiss_flag';
    case ResolveFlag = 'resolve_flag';

    public function label(): string
    {
        return match ($this) {
            self::Hide => 'Sembunyikan',
            self::Restore => 'Pulihkan',
            self::Remove => 'Hapus',
            self::Lock => 'Kunci',
            self::DismissFlag => 'Abaikan laporan konten',
            self::ResolveFlag => 'Tindak laporan konten',
        };
    }
}
