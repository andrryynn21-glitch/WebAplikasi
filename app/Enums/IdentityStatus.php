<?php

namespace App\Enums;

enum IdentityStatus: string
{
    case Unverified = 'unverified';
    case PendingVerification = 'pending_verification';
    case Verified = 'verified';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Unverified => 'Belum mengajukan verifikasi',
            self::PendingVerification => 'Menunggu verifikasi',
            self::Verified => 'Terverifikasi',
            self::Rejected => 'Ditolak',
        };
    }

    /** Hanya identitas terverifikasi yang boleh memakai fitur kelas. */
    public function grantsClassAccess(): bool
    {
        return $this === self::Verified;
    }

    /** Pesan aman untuk ditampilkan ke mahasiswa (tanpa detail internal). */
    public function publicMessage(): string
    {
        return match ($this) {
            self::Unverified => 'Unggah KTM dan ajukan verifikasi identitas untuk membuka fitur kelas.',
            self::PendingVerification => 'Berkas identitas sedang diperiksa. Fitur kelas belum aktif.',
            self::Rejected => 'Verifikasi identitas ditolak. Silakan periksa alasan dan ajukan ulang.',
            self::Verified => 'Identitas terverifikasi.',
        };
    }
}
