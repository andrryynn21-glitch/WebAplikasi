<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Enums\UserRole;
use App\Models\AppNotification;
use App\Models\ClassRoom;
use App\Models\Report;
use App\Models\SecurityEvent;
use App\Models\User;

/**
 * Notifikasi in-app bertingkat.
 *
 * Aturan privasi: judul dan pesan tidak pernah memuat isi pengaduan,
 * identitas pelapor, maupun lampiran. `data` hanya berisi referensi
 * (public_ref, id) yang tetap memerlukan otorisasi untuk dibuka.
 */
class NotificationService
{
    public function send(
        User $recipient,
        NotificationType $type,
        string $title,
        ?string $message = null,
        array $data = [],
        string $severity = 'info',
        ?string $actionUrl = null,
    ): AppNotification {
        $notification = new AppNotification([
            'user_id' => $recipient->id,
            'type' => $type->value,
            'title' => mb_substr($title, 0, 200),
            'message' => $message !== null ? mb_substr($message, 0, 500) : null,
            'data' => $this->scrub($data),
            'severity' => $severity,
            'action_url' => $actionUrl,
        ]);

        [$resource, $purpose] = $notification->visibilityContext();
        $notification->data = array_merge(['resource' => $resource, 'purpose' => $purpose], $notification->data);
        $notification->save();

        return $notification;
    }

    /**
     * Notifikasi untuk laporan: selalu memakai referensi publik,
     * tidak pernah judul/isi laporan.
     */
    public function reportEvent(
        User $recipient,
        Report $report,
        NotificationType $type,
        string $title,
        ?string $message = null,
        array $extra = [],
        string $severity = 'info',
    ): AppNotification {
        return $this->send(
            $recipient,
            $type,
            $title,
            $message,
            array_merge($extra, [
                'report_ref' => $report->public_ref,
                'status' => $report->status?->value,
                'class_id' => $report->class_id,
                'purpose' => $recipient->id === $report->reporter_id ? 'personal' : 'staff',
                'resource' => 'report',
            ]),
            $severity,
            '/api/v1/reports/'.$report->public_ref,
        );
    }

    /**
     * Kirim ke seluruh pengguna dengan permission tertentu.
     * Digunakan untuk notifikasi bertingkat ke pengelola sistem.
     */
    public function toUsersWithPermission(
        string $permission,
        NotificationType $type,
        string $title,
        ?string $message = null,
        array $data = [],
        string $severity = 'info',
        ?User $exclude = null,
    ): void {
        User::query()
            ->where('is_active', true)
            ->whereHas('role.permissions', fn ($q) => $q->where('key', $permission))
            ->when($exclude, fn ($q) => $q->whereKeyNot($exclude->id))
            ->with('role')
            ->chunkById(200, function ($users) use ($type, $title, $message, $data, $severity) {
                foreach ($users as $user) {
                    $this->sendToStaff($user, $type, $title, $message, $data, $severity);
                }
            });
    }

    public function toSuperAdmins(
        NotificationType $type,
        string $title,
        ?string $message = null,
        array $data = [],
        string $severity = 'info',
    ): void {
        User::query()
            ->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->where('key', UserRole::SuperAdmin->value))
            ->with('role')
            ->chunkById(200, function ($users) use ($type, $title, $message, $data, $severity) {
                foreach ($users as $user) {
                    $this->sendToStaff($user, $type, $title, $message, $data, $severity);
                }
            });
    }

    /**
     * Ketua kelas aktif sebuah kelas (untuk notifikasi kelas).
     */
    public function toClassLeaders(ClassRoom $class, NotificationType $type, string $title, ?string $message = null, array $data = []): void
    {
        $leaders = $class->administratorAssignments()
            ->where('starts_at', '<=', now())
            ->whereNull('ends_at')
            ->with('user.role')
            ->get()
            ->pluck('user')
            ->filter(fn ($user) => $user?->isClassLeaderOf($class->id));

        foreach ($leaders as $leader) {
            $this->sendToStaff($leader, $type, $title, $message, array_merge($data, ['class_id' => $class->id]));
        }
    }

    /**
     * Dosen pengampu aktif kelas.
     */
    public function toClassLecturers(ClassRoom $class, NotificationType $type, string $title, ?string $message = null, array $data = []): void
    {
        $lecturers = $class->lecturerAssignments()
            ->where('starts_at', '<=', now())
            ->whereNull('ends_at')
            ->with('lecturer.role')
            ->get()
            ->pluck('lecturer')
            ->filter(fn ($user) => $user?->isLecturerOf($class->id));

        foreach ($lecturers as $lecturer) {
            $this->sendToStaff($lecturer, $type, $title, $message, array_merge($data, ['class_id' => $class->id]));
        }
    }

    /**
     * Buang kunci yang berpotensi memuat konten sensitif.
     */
    private function scrub(array $data): array
    {
        $allowed = ['purpose', 'resource', 'report_ref', 'status', 'class_id', 'membership_id', 'aspiration_id',
            'moderation_action_id', 'security_event_id', 'severity', 'eligible_reviewers', 'appeal_id'];

        return collect($data)
            ->filter(fn ($value, $key) => in_array((string) $key, $allowed, true)
                && (is_scalar($value) || $value === null))
            ->all();
    }

    private function sendToStaff(User $recipient, NotificationType $type, string $title, ?string $message, array $data, string $severity = 'info'): void
    {
        $data = array_merge($this->scrub($data), ['purpose' => 'staff']);
        $notice = new AppNotification(['user_id' => $recipient->id, 'type' => $type, 'data' => $data]);
        if ($notice->isVisibleTo($recipient)) {
            $this->send($recipient, $type, $title, $message, $data, $severity);
        }
    }

    public function securityEvent(SecurityEvent $event, ?User $exclude = null): void
    {
        User::query()->active()
            ->whereHas('role.permissions', fn ($q) => $q->where('key', 'security.view'))
            ->when($exclude, fn ($q) => $q->whereKeyNot($exclude->id))
            ->with('role')
            ->chunkById(200, function ($users) use ($event) {
                foreach ($users as $user) {
                    if ($user->can('view', $event)) {
                        $this->send($user, NotificationType::SecurityNotice,
                            'Kejadian keamanan: '.$event->category->label(),
                            'Kejadian keamanan tercatat. Rincian tersedia di halaman security event.',
                            ['security_event_id' => $event->id, 'severity' => $event->severity->value, 'class_id' => $event->class_id],
                            $event->severity->value);
                    }
                }
            });
    }
}
