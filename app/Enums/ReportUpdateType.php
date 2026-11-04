<?php

namespace App\Enums;

enum ReportUpdateType: string
{
    case Update = 'update';
    case ClarificationRequest = 'clarification_request';
    case ClarificationResponse = 'clarification_response';
    case InternalNote = 'internal_note';
    case Assignment = 'assignment';

    public function label(): string
    {
        return match ($this) {
            self::Update => 'Pembaruan',
            self::ClarificationRequest => 'Permintaan klarifikasi',
            self::ClarificationResponse => 'Jawaban klarifikasi',
            self::InternalNote => 'Catatan internal',
            self::Assignment => 'Penugasan',
        };
    }

    public function isVisibleToReporter(): bool
    {
        return $this !== self::InternalNote;
    }
}
