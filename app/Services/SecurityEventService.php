<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Enums\SecurityEventCategory;
use App\Enums\SecuritySeverity;
use App\Models\RateLimitCooldown;
use App\Models\Report;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Pencatatan kejadian keamanan dan deteksi pengulangan.
 *
 * Aturan penting:
 * - Keputusan akses sudah terjadi sebelum service ini dipanggil. Tidak ada
 *   logika audio/notifikasi yang dapat mengubah hasil otorisasi.
 * - Tidak pernah mencatat password, token, header Authorization, atau isi
 *   pengaduan. `context` dibatasi pada metadata teknis.
 */
class SecurityEventService
{
    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Catat kejadian keamanan. Mengembalikan event yang dibuat agar
     * controller dapat menyertakan voice warning di response.
     */
    public function record(
        SecurityEventCategory $category,
        ?User $user,
        ?Request $request = null,
        ?Model $resource = null,
        ?string $reason = null,
        ?int $classId = null,
        array $context = [],
    ): SecurityEvent {
        $request ??= request();

        $severity = $category->severity();

        $event = SecurityEvent::create([
            'user_id' => $user?->id,
            'category' => $category->value,
            'severity' => $severity->value,
            'resource_type' => $resource ? class_basename($resource) : null,
            'resource_ref' => $resource instanceof Report
                ? $resource->public_ref
                : ($resource?->getKey() !== null ? (string) $resource->getKey() : null),
            'denial_reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
            'route' => $request->route()?->getName() ?? $request->path(),
            'method' => $request->method(),
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            'class_id' => $classId,
            'context' => $this->sanitizeContext($context),
        ]);

        $this->notifySecurityTeam($event, $user);

        return $event;
    }

    /**
     * Catat penolakan akses. Jika pelanggaran berulang melewati ambang,
     * terbitkan kejadian repeated_denials + cooldown.
     */
    public function recordDenial(
        SecurityEventCategory $category,
        ?User $user,
        ?Request $request = null,
        ?Model $resource = null,
        ?string $reason = null,
        ?int $classId = null,
    ): SecurityEvent {
        $event = $this->record($category, $user, $request, $resource, $reason, $classId);

        if ($user !== null && $this->activeCooldown($user, 'repeated_denials') === null && $this->shouldEscalate($user)) {
            $this->escalateRepeatedDenials($user, $request);
        }

        return $event;
    }

    /**
     * Apakah pengguna melewati ambang penolakan dalam jendela waktu.
     */
    public function shouldEscalate(User $user): bool
    {
        $threshold = (int) config('security.repeat_threshold', 5);
        $window = (int) config('security.repeat_window_seconds', 600);

        $count = SecurityEvent::query()
            ->where('user_id', $user->id)
            ->whereIn('category', [
                SecurityEventCategory::AccessDenied->value,
                SecurityEventCategory::CrossClassAccessAttempt->value,
                SecurityEventCategory::UnauthorizedAccessAttempt->value,
                SecurityEventCategory::PrivateFileAccessAttempt->value,
                SecurityEventCategory::PrivilegeEscalationAttempt->value,
            ])
            ->where('created_at', '>=', Carbon::now()->subSeconds($window))
            ->count();

        return $count >= $threshold;
    }

    public function escalateRepeatedDenials(User $user, ?Request $request = null): SecurityEvent
    {
        $cooldown = $this->startCooldown($user, 'repeated_denials', $request);

        $event = $this->record(
            SecurityEventCategory::RepeatedDenials,
            $user,
            $request,
            null,
            'Ambang penolakan berulang terlewati.',
            null,
            ['cooldown_until' => $cooldown->cooldown_until?->toIso8601String()],
        );

        $this->notifications->toSuperAdmins(
            NotificationType::SecurityNotice,
            'Percobaan akses berulang',
            'Terdeteksi pola penolakan akses berulang pada salah satu akun. Tinjau security event.',
            ['security_event_id' => $event->id],
            'warning',
        );

        return $event;
    }

    public function startCooldown(User $user, string $key, ?Request $request = null): RateLimitCooldown
    {
        $seconds = (int) config('security.cooldown_seconds', 900);

        return RateLimitCooldown::updateOrCreate(
            ['user_id' => $user->id, 'key' => $key],
            [
                'ip_address' => $request?->ip(),
                'last_attempt_at' => now(),
                'cooldown_until' => now()->addSeconds($seconds),
            ],
        );
    }

    /**
     * Cooldown aktif untuk aksi tertentu (misal pembuatan pengaduan).
     */
    public function activeCooldown(User $user, string $key): ?RateLimitCooldown
    {
        return RateLimitCooldown::query()
            ->where('user_id', $user->id)
            ->where('key', $key)
            ->where('cooldown_until', '>', now())
            ->first();
    }

    public function clearCooldown(User $user, string $key): void
    {
        RateLimitCooldown::query()
            ->where('user_id', $user->id)
            ->where('key', $key)
            ->delete();
    }

    /**
     * Metadata yang aman disimpan. Kunci sensitif dibuang.
     */
    private function sanitizeContext(array $context): array
    {
        $allowed = ['cooldown_until', 'retry_after', 'attempt_count', 'permission', 'category', 'status'];

        return collect($context)
            ->filter(fn ($value, $key) => in_array((string) $key, $allowed, true))
            ->map(fn ($value) => is_scalar($value) || $value === null
                ? (is_string($value) ? mb_substr($value, 0, 255) : $value)
                : '[structured]')
            ->all();
    }

    /**
     * Notifikasi bertingkat. Isi laporan tidak pernah disertakan.
     */
    private function notifySecurityTeam(SecurityEvent $event, ?User $actor): void
    {
        $severity = $event->severity;

        if ($severity->numeric() >= SecuritySeverity::High->numeric()) {
            $this->notifications->securityEvent($event, $actor);
        }

        if ($severity === SecuritySeverity::Critical) {
            Log::warning('Security event kritis', [
                'event_id' => $event->id,
                'category' => $event->category->value,
                'route' => $event->route,
            ]);
        }
    }
}
