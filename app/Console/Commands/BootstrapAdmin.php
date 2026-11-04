<?php

namespace App\Console\Commands;

use App\Enums\AuditAction;
use App\Enums\IdentityStatus;
use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

#[Signature('app:bootstrap-admin {--name=} {--email=}')]
#[Description('Membuat akun Super Admin pertama dengan password tersembunyi')]
class BootstrapAdmin extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $role = Role::query()->where('key', UserRole::SuperAdmin->value)->first();

        if ($role === null) {
            $this->error('Jalankan php artisan db:seed terlebih dahulu.');

            return self::FAILURE;
        }

        if (User::query()->where('role_id', $role->id)->exists()) {
            $this->error('Super Admin sudah tersedia. Gunakan proses administrasi untuk akun tambahan.');

            return self::FAILURE;
        }

        if (! $this->input->isInteractive()) {
            $this->error('Command ini memerlukan terminal interaktif untuk password tersembunyi.');

            return self::FAILURE;
        }

        $data = [
            'name' => $this->option('name') ?: $this->ask('Nama administrator'),
            'email' => $this->option('email') ?: $this->ask('Email administrator'),
            'password' => $this->secret('Password administrator'),
            'password_confirmation' => $this->secret('Konfirmasi password'),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        return DB::transaction(function () use ($role, $data): int {
            $role = Role::query()->lockForUpdate()->findOrFail($role->id);

            if (User::query()->where('role_id', $role->id)->exists()) {
                $this->error('Super Admin sudah tersedia.');

                return self::FAILURE;
            }

            $admin = User::create(collect($data)->only(['name', 'email', 'password'])->all());
            $admin->forceFill([
                'role_id' => $role->id,
                'identity_status' => IdentityStatus::Unverified,
                'is_active' => true,
            ])->save();

            app(AuditLogService::class)->record(
                AuditAction::RoleAssigned, $admin, $admin, null,
                ['role' => UserRole::SuperAdmin->value, 'source' => 'console_bootstrap'],
            );

            $this->info('Super Admin pertama berhasil dibuat. Akun ini tidak memiliki akses isi pengaduan.');

            return self::SUCCESS;
        });
    }
}
