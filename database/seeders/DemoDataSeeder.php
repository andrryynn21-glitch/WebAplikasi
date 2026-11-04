<?php

namespace Database\Seeders;

use App\Enums\IdentityStatus;
use App\Enums\UserRole;
use App\Models\AcademicTerm;
use App\Models\ClassAdministratorAssignment;
use App\Models\ClassMembership;
use App\Models\ClassRoom;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Data fiktif hanya untuk database lokal/test yang belum berisi akun atau kelas.
 * Akun mendapat password acak yang tidak dicetak. Gunakan bootstrap untuk login.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing']) || User::query()->exists() || ClassRoom::query()->exists()) {
            throw new RuntimeException('Seeder demo hanya diizinkan pada database lokal/test tanpa akun dan kelas.');
        }

        $term = AcademicTerm::query()->updateOrCreate(
            ['code' => '2026-1'],
            [
                'name' => 'Semester Ganjil 2026/2027',
                'starts_on' => '2026-09-01',
                'ends_on' => '2027-01-31',
                'is_active' => true,
            ],
        );

        $class = ClassRoom::query()->updateOrCreate(
            ['academic_term_id' => $term->id, 'code' => 'IF-23-A'],
            [
                'name' => 'Informatika 2023 Kelas A',
                'program_study' => 'Teknik Informatika',
                'semester' => 7,
                'is_active' => true,
                'join_code' => 'MASUKIF23A',
            ],
        );

        $roleId = fn (UserRole $role) => Role::query()->where('key', $role->value)->value('id');

        $make = function (string $name, string $email, UserRole $role, IdentityStatus $status, ?string $nim = null) use ($roleId): User {
            return User::query()->updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Hash::make(Str::random(48)),
                    'nim' => $nim,
                    'role_id' => $roleId($role),
                    'identity_status' => $status,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ],
            );
        };

        $make('Super Admin Demo', 'admin@example.test', UserRole::SuperAdmin, IdentityStatus::Verified);
        $dosen = $make('Dosen Koordinator Demo', 'dosen@example.test', UserRole::LecturerCoordinator, IdentityStatus::Verified);
        $petugas = $make('Petugas Verifikasi Demo', 'verifikator@example.test', UserRole::IdentityVerifier, IdentityStatus::Verified);
        $ketua = $make('Ketua Kelas Demo', 'ketua@example.test', UserRole::ClassLeader, IdentityStatus::Verified, '2310000001');
        $mahasiswa = $make('Mahasiswa Demo', 'mahasiswa@example.test', UserRole::Student, IdentityStatus::Verified, '2310000002');
        $belumVerifikasi = $make('Mahasiswa Belum Verifikasi', 'pending@example.test', UserRole::Student, IdentityStatus::PendingVerification, '2310000003');

        $class->lecturerAssignments()->firstOrCreate(
            ['lecturer_id' => $dosen->id],
            ['role' => 'coordinator', 'starts_at' => now()],
        );

        ClassAdministratorAssignment::query()->updateOrCreate(
            ['class_id' => $class->id, 'user_id' => $ketua->id],
            ['starts_at' => now(), 'ends_at' => null],
        );

        foreach ([$ketua, $mahasiswa] as $member) {
            ClassMembership::query()->updateOrCreate(
                ['user_id' => $member->id, 'class_id' => $class->id],
                [
                    'status' => 'active',
                    'nim' => $member->nim,
                    'approved_at' => now(),
                ],
            );
        }

        // Demo ini sengaja tidak membuat pengaduan: pengaduan berisi data
        // pribadi dan harus lahir dari alur aplikasi, bukan seeder.
        $this->command?->info('Data fiktif dibuat tanpa kredensial login bersama. Gunakan alur bootstrap pada instalasi aplikasi, bukan seeder ini.');
        $this->command?->table(
            ['Peran', 'Email'],
            [
                ['Super Admin', 'admin@example.test'],
                ['Dosen Koordinator', 'dosen@example.test'],
                ['Petugas Verifikasi', 'verifikator@example.test'],
                ['Ketua Kelas', 'ketua@example.test'],
                ['Mahasiswa', 'mahasiswa@example.test'],
                ['Belum verifikasi', 'pending@example.test ('.$belumVerifikasi->email.')'],
            ],
        );
    }
}
