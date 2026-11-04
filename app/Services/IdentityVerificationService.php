<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\IdentityStatus;
use App\Enums\NotificationType;
use App\Models\IdentityVerification;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Verifikasi identitas. Versi pertama tidak terintegrasi dengan API kampus;
 * keputusan dibuat oleh petugas verifikasi yang berwenang.
 *
 * Yang TIDAK dilakukan: OCR otomatis, menganggap format NIM/logo sebagai bukti,
 * atau mengklaim verifikasi resmi UNPAM.
 */
class IdentityVerificationService
{
    public function __construct(
        private readonly PrivateStorageService $storage,
        private readonly AuditLogService $audit,
        private readonly NotificationService $notifications,
    ) {}

    public function submit(User $user, UploadedFile $file, string $nim, string $fullName): IdentityVerification
    {
        Validator::make(['nim' => $nim, 'full_name' => $fullName], [
            'nim' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/'],
            'full_name' => ['required', 'string', 'max:120'],
        ])->validate();

        $path = null;

        try {
            return DB::transaction(function () use ($user, $file, $nim, $fullName, &$path) {
                app(UserAccessLock::class)->lock();
                $user = User::query()->lockForUpdate()->findOrFail($user->id);
                abort_unless($user->is_active && $user->hasPermission('identity.submit'), 403);
                $pending = $user->identityVerifications()
                    ->where('status', IdentityStatus::PendingVerification->value)
                    ->exists();

                if ($pending) {
                    throw ValidationException::withMessages([
                        'file' => 'Masih ada pengajuan verifikasi yang menunggu pemeriksaan.',
                    ]);
                }

                $approvedId = IdentityVerification::recordedApprovalIdFor($user);
                $legacy = ($user->isVerified() && $approvedId === null)
                    || $user->identityVerifications()->where(fn ($query) => $query
                        ->whereNull('retains_verified')
                        ->orWhere('before_snapshot->history_state', 'unresolved_legacy'))->exists();
                $verification = $this->storage->storeIdentityDocument($user, $file);
                $path = $verification->ktm_path;
                $verification->forceFill([
                    'nim' => $nim,
                    'full_name' => $fullName,
                    'retains_verified' => $user->isVerified(),
                    'previous_approved_id' => $approvedId,
                    // Observed profile state, not proof of a historical approval.
                    'before_snapshot' => [
                        'name' => $user->name,
                        'nim' => $user->nim,
                        'identity_status' => $user->identity_status->value,
                        'history_state' => $legacy ? 'unresolved_legacy' : ($approvedId === null ? 'no_approval' : 'recorded'),
                    ],
                ])->save();

                if (! $user->isVerified()) {
                    $user->forceFill(['identity_status' => IdentityStatus::PendingVerification])->save();
                }

                return $verification;
            });
        } catch (Throwable $exception) {
            $this->storage->delete($path);

            throw $exception;
        }
    }

    public function approve(IdentityVerification $verification, User $reviewer, ?string $note = null): IdentityVerification
    {
        return $this->decide($verification, $reviewer, true, $note);
    }

    public function reject(IdentityVerification $verification, User $reviewer, string $reason): IdentityVerification
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => 'Alasan penolakan wajib diisi.',
            ]);
        }

        return $this->decide($verification, $reviewer, false, $reason);
    }

    private function decide(IdentityVerification $verification, User $reviewer, bool $approve, ?string $note): IdentityVerification
    {
        Validator::make(['note' => $note], ['note' => ['nullable', 'string', 'max:500']])->validate();

        return DB::transaction(function () use ($verification, $reviewer, $approve, $note) {
            // Shared with role changes/suspension: catalog mutex, actor, target, candidate.
            app(UserAccessLock::class)->lock();
            $reviewer = User::query()->lockForUpdate()->findOrFail($reviewer->id);
            $targetId = IdentityVerification::query()->whereKey($verification->id)->value('user_id');
            $target = User::query()->lockForUpdate()->findOrFail($targetId);
            $verification = IdentityVerification::query()->lockForUpdate()->findOrFail($verification->id);
            $verification->setRelation('user', $target);
            Gate::forUser($reviewer)->authorize('review', $verification);
            abort_unless($target->is_active && $target->hasPermission('identity.submit'), 403);

            if ($verification->status !== IdentityStatus::PendingVerification) {
                throw ValidationException::withMessages(['verification' => 'Pengajuan ini sudah diputuskan.']);
            }

            if ($verification->retains_verified === null || $verification->before_snapshot === null) {
                throw ValidationException::withMessages(['verification' => 'Pengajuan lama memerlukan rekonsiliasi riwayat identitas. Data tidak diubah otomatis.']);
            }

            $before = $verification->before_snapshot;
            $expectedStatus = $verification->retains_verified ? IdentityStatus::Verified : IdentityStatus::PendingVerification;
            if ($target->identity_status !== $expectedStatus
                || $target->name !== ($before['name'] ?? null)
                || $target->nim !== ($before['nim'] ?? null)
                || $verification->previous_approved_id !== IdentityVerification::recordedApprovalIdFor($target)
                || $target->identityVerifications()->where('status', IdentityStatus::PendingVerification->value)->count() !== 1
                || (int) $target->identityVerifications()->max('id') !== $verification->id) {
                throw ValidationException::withMessages(['verification' => 'Status identitas berubah. Muat ulang dan periksa pengajuan yang masih aktif.']);
            }

            $verification->forceFill([
                'status' => $approve ? IdentityStatus::Verified : IdentityStatus::Rejected,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'rejection_reason' => $approve ? null : $note,
                'review_note' => $note,
            ])->save();

            if ($approve) {
                $target->forceFill([
                    'identity_status' => IdentityStatus::Verified,
                    'nim' => $verification->nim,
                    'name' => $verification->full_name,
                ])->save();
            } elseif (! $verification->retains_verified) {
                $target->forceFill(['identity_status' => IdentityStatus::Rejected])->save();
            }

            $this->audit->record($approve ? AuditAction::IdentityApproved : AuditAction::IdentityRejected, $reviewer, $verification, null, [
                'user_id' => $verification->user_id,
                'status' => $verification->status->value,
            ]);

            $this->notifications->send(
                $target,
                NotificationType::IdentityVerification,
                $approve ? 'Identitas terverifikasi' : 'Pengajuan identitas ditolak',
                $approve
                    ? 'Pengajuan identitas disetujui. Periksa status pada halaman akun.'
                    : 'Pengajuan identitas ditolak. Periksa alasan privat dan status identitas pada halaman akun.',
                [],
            );

            return $verification;
        });
    }
}
