<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\NotificationType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CredentialService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly NotificationService $notifications,
    ) {}

    public function change(User $user, string $currentPassword, string $newPassword): void
    {
        $token = $user->currentAccessToken();
        $tokenId = is_object($token) && method_exists($token, 'getKey') ? $token->getKey() : null;

        DB::transaction(function () use ($user, $currentPassword, $newPassword, $tokenId): void {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            if (! Hash::check($currentPassword, $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'Password saat ini tidak sesuai.']);
            }
            if (Hash::check($newPassword, $user->password)) {
                throw ValidationException::withMessages(['password' => 'Password baru harus berbeda dari password saat ini.']);
            }

            $user->forceFill(['password' => $newPassword, 'remember_token' => Str::random(60)])->save();
            $query = $user->tokens();
            $tokenId !== null ? $query->whereKeyNot($tokenId)->delete() : $query->delete();
            $this->audit->record(AuditAction::PasswordChanged, $user, $user);
            $this->notifications->send($user, NotificationType::SecurityNotice,
                'Password akun diubah', 'Password akun Anda berhasil diubah. Sesi lain telah diakhiri.', [], 'warning');
        });
    }

    public function requestReset(string $email): void
    {
        if (! $this->mailCanDeliverReset()) {
            Log::warning('Permintaan reset password tidak dikirim: mailer aman belum dikonfigurasi.');

            return;
        }

        $status = Password::broker()->sendResetLink(['email' => $email]);
        if ($status !== Password::RESET_LINK_SENT) {
            return;
        }

        $user = User::query()->where('email', $email)->first();
        if ($user !== null) {
            $this->audit->record(AuditAction::PasswordResetRequested, null, $user);
            $this->notifications->send($user, NotificationType::SecurityNotice,
                'Pemulihan password diminta', 'Tautan pemulihan password telah dikirim ke email akun.', [], 'warning');
        }
    }

    public function reset(array $credentials): void
    {
        $status = DB::transaction(function () use ($credentials) {
            DB::table(config('auth.passwords.users.table'))->where('email', $credentials['email'])->lockForUpdate()->first();

            return Password::broker()->reset($credentials, function (User $user, string $password): void {
                $user = User::query()->lockForUpdate()->findOrFail($user->id);
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                $user->tokens()->delete();
                $this->audit->record(AuditAction::PasswordResetCompleted, $user, $user);
                $this->notifications->send($user, NotificationType::SecurityNotice,
                    'Password akun dipulihkan', 'Password akun berhasil diatur ulang. Semua sesi lama telah diakhiri.', [], 'warning');
            });
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => 'Tautan pemulihan tidak valid atau sudah kedaluwarsa.']);
        }
    }

    private function mailCanDeliverReset(): bool
    {
        $mailer = (string) config('mail.default');
        $transport = (string) config("mail.mailers.{$mailer}.transport");

        if ($transport === 'array') {
            return app()->environment('testing');
        }
        if ($transport === 'log' || $transport === '') {
            return false;
        }
        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            foreach ((array) config("mail.mailers.{$mailer}.mailers", []) as $child) {
                if (in_array(config("mail.mailers.{$child}.transport"), ['log', 'array', null], true)) {
                    return false;
                }
            }
        }

        return true;
    }
}
