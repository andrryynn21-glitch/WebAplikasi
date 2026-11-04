<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Audit log administratif. Hanya metadata perubahan.
 */
class AuditLogService
{
    public function record(
        AuditAction $action,
        ?User $actor = null,
        ?Model $subject = null,
        ?int $classId = null,
        array $changes = [],
        ?Request $request = null,
    ): AuditLog {
        $request ??= request();

        return AuditLog::create([
            'actor_id' => $actor?->id,
            'actor_role' => $actor?->role?->key?->value,
            'action' => $action->value,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'class_id' => $classId,
            'changes' => $changes === [] ? null : $this->sanitizeChanges($changes),
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);
    }

    public function sanitizeChanges(array $changes): array
    {
        $allowed = ['from', 'to', 'status', 'action', 'category', 'sensitivity', 'routing_mode',
            'type', 'decision', 'role', 'user_id', 'assignee_id', 'previous_assignee_id',
            'lecturer_id', 'leader_id', 'replaced', 'revoked', 'max_uses', 'expires_at',
            'code', 'name', 'program_study', 'semester', 'is_active'];
        $safe = [];

        foreach ($changes as $key => $value) {
            if (in_array($key, ['before', 'after'], true) && is_array($value)) {
                $safe[$key] = $this->sanitizeChanges($value);
            } elseif (in_array($key, $allowed, true) && (is_scalar($value) || $value === null)) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }
}
