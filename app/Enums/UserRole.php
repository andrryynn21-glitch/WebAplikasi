<?php

namespace App\Enums;

enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case LecturerCoordinator = 'lecturer_coordinator';
    case ClassLeader = 'class_leader';
    case Student = 'student';
    case IdentityVerifier = 'identity_verifier';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin / Developer',
            self::LecturerCoordinator => 'Dosen Koordinator',
            self::ClassLeader => 'Ketua Kelas',
            self::Student => 'Mahasiswa',
            self::IdentityVerifier => 'Petugas Verifikasi Identitas',
        };
    }

    /**
     * Role yang boleh dipilih sendiri saat registrasi publik.
     * Role istimewa hanya diberikan lewat proses administratif.
     *
     * @return array<int, self>
     */
    public static function selfAssignable(): array
    {
        return [self::Student];
    }

    /**
     * Role yang secara default dapat melihat isi pengaduan privat
     * bila sudah ditugaskan. Super Admin sengaja tidak termasuk.
     */
    public function canEverReadPrivateDetails(): bool
    {
        return $this === self::LecturerCoordinator || $this === self::ClassLeader;
    }
}
