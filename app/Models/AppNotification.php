<?php

namespace App\Models;

use App\Enums\NotificationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Notifikasi in-app. Nama model Notifikasi dipisah dari trait Notifiable
 * agar tidak bentrok dengan notifikasi bawaan Laravel.
 *
 * Payload TIDAK PERNAH memuat isi pengaduan privat — hanya referensi.
 */
class AppNotification extends Model
{
    protected $table = 'notifications';

    protected $fillable = [
        'user_id', 'type', 'title', 'message', 'data', 'severity', 'action_url', 'read_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $visible = $user->is_active
            ? static::query()->where('user_id', $user->id)->lazyById(200)
                ->filter(fn (self $notice) => $notice->isVisibleTo($user))->pluck('id')->all()
            : [];

        return $query->where('user_id', $user->id)->whereIn($query->qualifyColumn('id'), $visible);
    }

    /** Resolve legacy payloads by concrete references, never by message text. */
    public function visibilityContext(): array
    {
        $data = $this->data ?? [];
        if (! is_array($data)
            || (isset($data['class_id']) && ! is_int($data['class_id'])
                && ! (is_string($data['class_id']) && ctype_digit($data['class_id'])))
            || (array_key_exists('appeal_id', $data) && ! array_key_exists('report_ref', $data))) {
            return ['unknown', 'staff', null];
        }
        $references = array_intersect_key($data, array_flip([
            'report_ref', 'membership_id', 'security_event_id', 'aspiration_id', 'moderation_action_id',
        ]));
        if (count($references) > 1) {
            return ['unknown', 'staff', null];
        }
        if (array_key_exists('report_ref', $data)) {
            $report = is_string($data['report_ref'])
                ? Report::query()->where('public_ref', $data['report_ref'])->first() : null;

            if (array_key_exists('appeal_id', $data)
                && (! is_numeric($data['appeal_id']) || ! $report?->appeals()->whereKey($data['appeal_id'])->exists())) {
                return ['unknown', 'staff', null];
            }

            return ['report', $report?->reporter_id === $this->user_id
                && ! in_array($this->type, [NotificationType::ReportAssigned, NotificationType::AppealSubmitted], true)
                    ? 'personal' : 'staff', $report];
        }
        if (array_key_exists('membership_id', $data)) {
            $membership = is_numeric($data['membership_id']) ? ClassMembership::find($data['membership_id']) : null;

            return ['membership', $membership?->user_id === $this->user_id ? 'personal' : 'staff', $membership];
        }
        if (array_key_exists('security_event_id', $data)) {
            return ['security_event', 'staff', is_numeric($data['security_event_id']) ? SecurityEvent::find($data['security_event_id']) : null];
        }
        if (array_key_exists('aspiration_id', $data)) {
            return ['aspiration', 'personal', is_numeric($data['aspiration_id']) ? Aspiration::find($data['aspiration_id']) : null];
        }
        if (array_key_exists('moderation_action_id', $data)) {
            return ['moderation', 'personal', is_numeric($data['moderation_action_id']) ? ModerationAction::find($data['moderation_action_id']) : null];
        }
        if ($this->type === NotificationType::MembershipDecision && is_numeric($data['class_id'] ?? null)
            && array_diff_key($data, array_flip(['class_id', 'purpose', 'resource'])) === []) {
            // Existing direct decisions/leader appointments refer to the
            // recipient's own membership; review requests have membership_id.
            return ['membership', 'personal', ClassMembership::query()
                ->where('class_id', $data['class_id'])->where('user_id', $this->user_id)->first()];
        }
        if (in_array($this->type, [NotificationType::SecurityNotice, NotificationType::IdentityVerification], true)
            && array_diff_key($data, array_flip(['purpose', 'resource'])) === []
            && $this->action_url === null) {
            return ['account', 'personal', null];
        }

        return ['unknown', 'staff', null];
    }

    public function isVisibleTo(User $user): bool
    {
        if (! $user->is_active || $this->user_id !== $user->id) {
            return false;
        }

        [$resource, $inferredPurpose, $record] = $this->visibilityContext();
        if ($resource === 'unknown') {
            return false;
        }
        $data = $this->data ?? [];
        $purpose = $data['purpose'] ?? $inferredPurpose;
        if (($data['resource'] ?? $resource) !== $resource || ! in_array($purpose, ['personal', 'staff'], true)) {
            return false;
        }
        if ($record !== null && in_array($resource, ['report', 'membership', 'security_event', 'aspiration'], true)
            && isset($data['class_id']) && (string) $data['class_id'] !== (string) $record->class_id) {
            return false;
        }

        return match ($resource) {
            'report' => $record !== null && $user->can('view', $record)
                && ($purpose === 'personal' ? $record->reporter_id === $user->id : $record->reporter_id !== $user->id),
            'membership' => $record !== null && ($purpose === 'personal'
                ? $record->user_id === $user->id
                : $record->user_id !== $user->id && $record->classRoom !== null
                    && $user->can('manageMemberships', $record->classRoom)),
            'security_event' => $purpose === 'staff' && $record !== null && $user->can('view', $record),
            'aspiration' => $purpose === 'personal' && $record !== null
                && $record->author_id === $user->id && $user->can('view', $record),
            'moderation' => $purpose === 'personal' && $record?->moderatable?->author_id === $user->id
                && in_array((int) ($data['class_id'] ?? 0), $user->accessibleClassIds(), true),
            'account' => $purpose === 'personal',
            default => false,
        };
    }
}
