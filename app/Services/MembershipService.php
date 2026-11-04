<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\IdentityStatus;
use App\Enums\MembershipStatus;
use App\Enums\NotificationType;
use App\Enums\UserRole;
use App\Models\ClassInvitationCode;
use App\Models\ClassMembership;
use App\Models\ClassRoom;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Keanggotaan kelas.
 *
 * Aturan inti:
 * - Kode undangan hanya tiket masuk. Kode yang valid TIDAK mengaktifkan
 *   keanggotaan dan TIDAK menggantikan verifikasi identitas.
 * - Keanggotaan baru berstatus active setelah identitas terverifikasi dan
 *   disetujui pengelola.
 * - Satu mahasiswa hanya boleh punya satu keanggotaan aktif.
 */
class MembershipService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Ajukan permintaan bergabung. Selalu menghasilkan status pending
     * (atau rejected bila kode tidak sah), tidak pernah langsung active.
     */
    public function requestJoin(User $user, string $invitationCode): ClassMembership
    {
        abort_unless($user->is_active && $user->hasRole(UserRole::Student, UserRole::ClassLeader), 403);
        if (! $user->isVerified()) {
            throw ValidationException::withMessages([
                'invitation_code' => 'Identitas belum terverifikasi. Kode undangan tidak menggantikan verifikasi identitas.',
            ]);
        }

        return DB::transaction(function () use ($user, $invitationCode) {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            if (! $user->isVerified()) {
                throw ValidationException::withMessages(['invitation_code' => 'Identitas belum terverifikasi.']);
            }

            $code = ClassInvitationCode::query()
                ->where('code', $invitationCode)
                ->lockForUpdate()
                ->first();

            if ($code === null || ! $code->isUsable() || ! $code->classRoom->is_active) {
                throw ValidationException::withMessages([
                    'invitation_code' => 'Kode undangan tidak valid atau sudah kedaluwarsa.',
                ]);
            }

            $existingActive = ClassMembership::query()
                ->where('user_id', $user->id)
                ->where('status', MembershipStatus::Active->value)
                ->exists();

            if ($existingActive) {
                throw ValidationException::withMessages([
                    'invitation_code' => 'Anda sudah memiliki keanggotaan kelas aktif.',
                ]);
            }

            $membership = ClassMembership::query()->firstOrNew([
                'user_id' => $user->id,
                'class_id' => $code->class_id,
            ]);

            if ($membership->exists && in_array($membership->status, [MembershipStatus::Active, MembershipStatus::Pending], true)) {
                throw ValidationException::withMessages([
                    'invitation_code' => 'Permintaan untuk kelas ini sudah tercatat.',
                ]);
            }

            $membership->fill([
                'status' => MembershipStatus::Pending,
                'nim' => $user->nim,
                'decision_note' => null,
                'approved_by' => null,
                'approved_at' => null,
            ])->save();

            $code->consume();

            $this->notifications->toClassLeaders(
                $code->classRoom,
                NotificationType::MembershipDecision,
                'Permintaan bergabung kelas',
                'Ada mahasiswa yang meminta bergabung ke kelas '.$code->classRoom->name.'.',
                ['membership_id' => $membership->id],
            );

            return $membership;
        });
    }

    public function approve(ClassMembership $membership, User $actor, ?string $note = null): ClassMembership
    {
        Gate::forUser($actor)->authorize('review', $membership);

        return DB::transaction(function () use ($membership, $actor, $note) {
            $user = User::query()->lockForUpdate()->findOrFail($membership->user_id);
            $locked = ClassMembership::query()->lockForUpdate()->findOrFail($membership->id);
            Gate::forUser($actor)->authorize('review', $locked);
            if (! $user->is_active || ! $user->isVerified() || ! $locked->classRoom->is_active) {
                throw ValidationException::withMessages(['membership' => 'Identitas mahasiswa belum terverifikasi atau akun/kelas tidak aktif.']);
            }
            if ($locked->status !== MembershipStatus::Pending) {
                throw ValidationException::withMessages(['membership' => 'Permintaan ini sudah diputuskan.']);
            }

            $otherActive = ClassMembership::query()
                ->where('user_id', $locked->user_id)
                ->where('id', '!=', $locked->id)
                ->where('status', MembershipStatus::Active->value)
                ->exists();

            if ($otherActive) {
                throw ValidationException::withMessages([
                    'membership' => 'Mahasiswa sudah memiliki keanggotaan kelas aktif.',
                ]);
            }

            $locked->forceFill([
                'status' => MembershipStatus::Active,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'decision_note' => $note,
            ])->save();

            $this->audit->record(AuditAction::MembershipApproved, $actor, $locked, $locked->class_id, [
                'user_id' => $locked->user_id,
            ]);

            $this->notifications->send(
                $locked->user,
                NotificationType::MembershipDecision,
                'Keanggotaan kelas disetujui',
                'Anda kini terdaftar pada '.$locked->classRoom->name.'.',
                ['class_id' => $locked->class_id],
            );

            return $locked;
        });
    }

    public function reject(ClassMembership $membership, User $actor, string $reason): ClassMembership
    {
        Gate::forUser($actor)->authorize('review', $membership);
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'note' => 'Alasan penolakan wajib diisi.',
            ]);
        }

        return DB::transaction(function () use ($membership, $actor, $reason) {
            User::query()->lockForUpdate()->findOrFail($membership->user_id);
            $membership = ClassMembership::query()->lockForUpdate()->findOrFail($membership->id);
            if ($membership->status !== MembershipStatus::Pending) {
                throw ValidationException::withMessages(['membership' => 'Permintaan ini sudah diputuskan.']);
            }
            $membership->forceFill([
                'status' => MembershipStatus::Rejected,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'decision_note' => $reason,
            ])->save();

            $this->audit->record(AuditAction::MembershipRejected, $actor, $membership, $membership->class_id, [
                'user_id' => $membership->user_id,
                'reason' => $reason,
            ]);

            $this->notifications->send(
                $membership->user,
                NotificationType::MembershipDecision,
                'Permintaan bergabung ditolak',
                'Permintaan bergabung ke kelas ditolak. Periksa alasan pada detail keanggotaan.',
                ['class_id' => $membership->class_id],
            );

            return $membership;
        });
    }

    public function revoke(ClassMembership $membership, User $actor, string $reason): void
    {
        Gate::forUser($actor)->authorize('review', $membership);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['note' => 'Alasan pencabutan wajib diisi.']);
        }
        DB::transaction(function () use ($membership, $actor, $reason) {
            User::query()->lockForUpdate()->findOrFail($membership->user_id);
            $membership = ClassMembership::query()->lockForUpdate()->findOrFail($membership->id);
            if ($membership->status !== MembershipStatus::Active) {
                throw ValidationException::withMessages(['membership' => 'Keanggotaan tidak aktif.']);
            }
            $membership->forceFill([
                'status' => MembershipStatus::Revoked,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'decision_note' => $reason,
            ])->save();

            $this->audit->record(AuditAction::MembershipRejected, $actor, $membership, $membership->class_id, [
                'user_id' => $membership->user_id,
                'revoked' => true,
                'reason' => $reason,
            ]);
        });
    }

    /**
     * Setelah identitas diverifikasi: aktifkan permintaan pending? Tidak.
     * Verifikasi identitas hanya membuka jalan; persetujuan kelas tetap
     * keputusan manusia. Metode ini hanya dipakai untuk pembersihan
     * keanggotaan ganda bila terjadi.
     */
    public function ensureSingleActiveMembership(User $user): void
    {
        $active = ClassMembership::query()
            ->where('user_id', $user->id)
            ->where('status', MembershipStatus::Active->value)
            ->orderBy('approved_at')
            ->get();

        if ($active->count() <= 1) {
            return;
        }

        throw new LogicException('Terdeteksi lebih dari satu keanggotaan aktif; perlu penanganan manual.');
    }

    public function pendingCount(ClassRoom $class): int
    {
        return ClassMembership::query()
            ->where('class_id', $class->id)
            ->where('status', MembershipStatus::Pending->value)
            ->count();
    }

    public function identityLabel(User $user): string
    {
        return $user->identity_status instanceof IdentityStatus
            ? $user->identity_status->label()
            : 'Tidak diketahui';
    }
}
