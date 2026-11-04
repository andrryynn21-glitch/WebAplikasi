<?php

namespace App\Enums;

enum AttachmentType: string
{
    case ReportAttachment = 'report_attachment';
    case IdentityDocument = 'identity_document';
    case AspirationImage = 'aspiration_image';

    public function label(): string
    {
        return match ($this) {
            self::ReportAttachment => 'Lampiran pengaduan',
            self::IdentityDocument => 'Dokumen identitas',
            self::AspirationImage => 'Lampiran aspirasi',
        };
    }

    /** Semua jenis lampiran disimpan di disk privat. */
    public function disk(): string
    {
        return 'private';
    }

    public function maxSizeKb(): int
    {
        return match ($this) {
            self::IdentityDocument => 4096,
            self::ReportAttachment => 8192,
            self::AspirationImage => 4096,
        };
    }

    /**
     * @return array<int, string>
     */
    public function allowedMimeTypes(): array
    {
        return match ($this) {
            self::IdentityDocument => ['image/jpeg', 'image/png', 'application/pdf'],
            self::ReportAttachment => ['image/jpeg', 'image/png', 'application/pdf', 'text/plain'],
            self::AspirationImage => ['image/jpeg', 'image/png'],
        };
    }
}
