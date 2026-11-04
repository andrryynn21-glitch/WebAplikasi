<?php

namespace App\Services;

use App\Enums\AspirationStatus;
use App\Enums\AuditAction;
use App\Enums\CommentStatus;
use App\Enums\ContentFlagStatus;
use App\Enums\ModerationActionType;
use App\Enums\NotificationType;
use App\Models\Aspiration;
use App\Models\AspirationComment;
use App\Models\ContentFlag;
use App\Models\ModerationAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Moderasi konten aspirasi. Setiap tindakan tercatat di moderation_actions
 * dan audit log. Moderator tidak dapat memoderasi kontennya sendiri.
 */
class ModerationService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly NotificationService $notifications,
    ) {}

    public function flag(Model $content, User $reporter, string $reason, ?string $note = null): ContentFlag
    {
        $this->assertContent($content);
        Gate::forUser($reporter)->authorize('flag', $content);

        if ($content->author_id === $reporter->id) {
            throw ValidationException::withMessages([
                'reason' => 'Anda tidak dapat melaporkan konten Anda sendiri.',
            ]);
        }

        $classId = $content instanceof Aspiration ? $content->class_id : $content->aspiration->class_id;
        if ($classId !== $reporter->activeClassId()) {
            throw ValidationException::withMessages([
                'reason' => 'Konten berada di luar kelas Anda.',
            ]);
        }

        return ContentFlag::query()->firstOrCreate([
            'flaggable_type' => $content->getMorphClass(),
            'flaggable_id' => $content->getKey(),
            'reported_by' => $reporter->id,
        ], [
            'reason' => $reason,
            'note' => $note,
            'status' => ContentFlagStatus::Open,
        ]);
    }

    public function moderate(
        Model $content,
        User $moderator,
        ModerationActionType $action,
        string $reason,
        ?ContentFlag $flag = null,
    ): ModerationAction {
        $this->assertContent($content);
        Gate::forUser($moderator)->authorize('moderate', $content);

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => 'Alasan moderasi wajib diisi.',
            ]);
        }

        if ($content->author_id === $moderator->id) {
            throw ValidationException::withMessages([
                'reason' => 'Anda tidak dapat memoderasi konten Anda sendiri.',
            ]);
        }

        return DB::transaction(function () use ($content, $moderator, $action, $reason, $flag) {
            $aspiration = $content instanceof AspirationComment
                ? Aspiration::query()->lockForUpdate()->findOrFail($content->aspiration_id)
                : null;
            $content = $content->newQuery()->lockForUpdate()->findOrFail($content->id);
            Gate::forUser($moderator)->authorize('moderate', $content);
            if ($flag !== null) {
                $flag = ContentFlag::query()->lockForUpdate()->findOrFail($flag->id);
                if ($flag->flaggable_type !== $content->getMorphClass() || $flag->flaggable_id !== $content->id
                    || $flag->status !== ContentFlagStatus::Open) {
                    throw ValidationException::withMessages(['flag_id' => 'Laporan konten tidak sesuai atau sudah diputuskan.']);
                }
            } elseif (in_array($action, [ModerationActionType::DismissFlag, ModerationActionType::ResolveFlag], true)) {
                throw ValidationException::withMessages(['flag_id' => 'Laporan konten wajib dipilih.']);
            }

            $previous = $content->status;

            $status = $this->resolveTargetStatus($content, $action);

            if ($status !== null) {
                $content->forceFill(['status' => $status])->save();
                if ($aspiration !== null) {
                    $aspiration->forceFill([
                        'comments_count' => $aspiration->comments()->visible()->count(),
                        'last_activity_at' => now(),
                    ])->save();
                }
            }

            $record = ModerationAction::create([
                'moderatable_type' => $content->getMorphClass(),
                'moderatable_id' => $content->getKey(),
                'moderator_id' => $moderator->id,
                'action' => $action,
                'reason' => $reason,
                'content_flag_id' => $flag?->id,
                'previous_status' => $previous?->value,
                'resulting_status' => $status?->value,
            ]);

            if ($flag !== null) {
                $flag->forceFill([
                    'status' => $action === ModerationActionType::DismissFlag
                        ? ContentFlagStatus::Dismissed
                        : ContentFlagStatus::Resolved,
                    'reviewed_by' => $moderator->id,
                    'reviewed_at' => now(),
                ])->save();
            }

            $classId = $content instanceof Aspiration ? $content->class_id : $content->aspiration->class_id;
            $this->audit->record(AuditAction::ContentModerated, $moderator, $content, $classId, [
                'action' => $action->value,
                'from' => $previous?->value,
                'to' => $status?->value,
                'reason' => $reason,
            ]);

            $this->notifications->send(
                $content->author,
                NotificationType::ContentModerated,
                'Konten dimoderasi',
                'Salah satu konten Anda mendapat tindakan moderasi. Periksa detail untuk informasinya.',
                ['moderation_action_id' => $record->id, 'class_id' => $classId],
                'warning',
            );

            return $record;
        });
    }

    /**
     * Status konten baru. Null berarti status konten tidak berubah
     * (aksi hanya menutup laporan konten).
     */
    private function resolveTargetStatus(Model $content, ModerationActionType $action): AspirationStatus|CommentStatus|null
    {
        $isAspiration = $content instanceof Aspiration;

        if ($action === ModerationActionType::Lock) {
            if (! $isAspiration) {
                throw ValidationException::withMessages([
                    'action' => 'Aksi kunci hanya berlaku untuk aspirasi.',
                ]);
            }

            return AspirationStatus::Locked;
        }

        if (in_array($action, [ModerationActionType::DismissFlag, ModerationActionType::ResolveFlag], true)) {
            return null;
        }

        return match ($action) {
            ModerationActionType::Hide => $isAspiration ? AspirationStatus::Hidden : CommentStatus::Hidden,
            ModerationActionType::Remove => $isAspiration ? AspirationStatus::Removed : CommentStatus::Removed,
            ModerationActionType::Restore => $isAspiration ? AspirationStatus::Published : CommentStatus::Published,
        };
    }

    private function assertContent(Model $content): void
    {
        if (! $content instanceof Aspiration && ! $content instanceof AspirationComment) {
            throw ValidationException::withMessages([
                'content' => 'Tipe konten tidak didukung untuk moderasi.',
            ]);
        }
    }
}
