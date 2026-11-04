<?php

namespace App\Services;

use App\Enums\AppealStatus;
use App\Enums\ReportStatus;
use App\Models\Appeal;
use App\Models\Aspiration;
use App\Models\ClassMembership;
use App\Models\ClassRoom;
use App\Models\ContentFlag;
use App\Models\IdentityVerification;
use App\Models\Report;
use App\Models\SecurityEvent;
use App\Models\User;

/**
 * Dashboard per role. Setiap query dibatasi kelas yang boleh diakses
 * pengguna. Super Admin hanya menerima data sistem (akun, kelas,
 * security event) — bukan isi atau daftar pengaduan.
 */
class DashboardService
{
    public function __construct(
        private readonly StatisticsService $statistics,
    ) {}

    public function forStudent(User $user): array
    {
        $classId = $user->activeClassId();

        $mine = Report::query()->where('reporter_id', $user->id)->visibleTo($user);

        return [
            'role' => 'student',
            'identity' => [
                'status' => $user->identity_status->value,
                'message' => $user->identity_status->publicMessage(),
            ],
            'membership' => $this->membershipSummary($user),
            'reports' => [
                'total' => (clone $mine)->count(),
                'open' => (clone $mine)->open()->count(),
                'resolved' => (clone $mine)->where('status', ReportStatus::Resolved->value)->count(),
                'needs_clarification' => (clone $mine)->where('status', ReportStatus::NeedsClarification->value)->count(),
            ],
            'recent_reports' => (clone $mine)
                ->latest('last_activity_at')
                ->limit(5)
                ->get()
                ->map(fn (Report $r) => $this->reportCard($r)),
            'aspirations' => $user->canUseClassFeatures() && $classId !== null
                ? $this->recentAspirations($classId)
                : [],
        ];
    }

    public function forClassLeader(User $user): array
    {
        $classIds = $user->managedClassIds();

        $assigned = Report::query()
            ->whereIn('class_id', $classIds)
            ->visibleTo($user)
            ->whereHas('activeAssignments', fn ($q) => $q->where('assignee_id', $user->id));

        return [
            'role' => 'class_leader',
            'classes' => ClassRoom::query()->whereIn('id', $classIds)->get(['id', 'code', 'name']),
            'reports' => [
                'assigned_open' => (clone $assigned)->open()->count(),
                'waiting_clarification' => (clone $assigned)->where('status', ReportStatus::NeedsClarification->value)->count(),
                'resolved_by_me' => (clone $assigned)->where('status', ReportStatus::Resolved->value)->count(),
            ],
            'pending_memberships' => ClassMembership::query()
                ->whereIn('class_id', $classIds)
                ->where('status', 'pending')
                ->count(),
            'open_flags' => $classIds === []
                ? 0
                : ContentFlag::query()
                    ->where('status', 'open')
                    ->whereIn('flaggable_id', Aspiration::query()->whereIn('class_id', $classIds)->select('id'))
                    ->where('flaggable_type', Aspiration::class)
                    ->count(),
            'aspirations' => $classIds === [] ? [] : $this->recentAspirations($classIds),
            'statistics' => $this->statistics->summary($user),
        ];
    }

    public function forLecturer(User $user): array
    {
        $classIds = $user->managedClassIds();

        $inScope = Report::query()->whereIn('class_id', $classIds)->visibleTo($user);

        return [
            'role' => 'lecturer_coordinator',
            'classes' => ClassRoom::query()->whereIn('id', $classIds)->get(['id', 'code', 'name']),
            'reports' => [
                'needs_review' => (clone $inScope)->where('status', ReportStatus::NeedsReview->value)->count(),
                'under_review' => (clone $inScope)->where('status', ReportStatus::UnderReview->value)->count(),
                'waiting_follow_up' => (clone $inScope)->where('status', ReportStatus::WaitingFollowUp->value)->count(),
                'resolution_proposed' => (clone $inScope)->where('status', ReportStatus::ResolutionProposed->value)->count(),
            ],
            'appeals' => [
                'pending' => Appeal::query()
                    ->whereIn('status', [AppealStatus::Pending->value, AppealStatus::UnderReview->value])
                    ->whereHas('report', fn ($q) => $q->whereIn('class_id', $classIds)->visibleTo($user))
                    ->count(),
            ],
            'needs_attention' => (clone $inScope)
                ->whereIn('status', [
                    ReportStatus::NeedsReview->value,
                    ReportStatus::WaitingFollowUp->value,
                    ReportStatus::ResolutionProposed->value,
                ])
                ->latest('last_activity_at')
                ->limit(10)
                ->get()
                ->map(fn (Report $r) => $this->reportCard($r)),
            'statistics' => $this->statistics->summary($user),
        ];
    }

    /**
     * Super Admin: hanya data sistem. Tidak ada daftar atau isi pengaduan.
     */
    public function forSuperAdmin(User $user): array
    {
        return [
            'role' => 'super_admin',
            'system' => [
                'active_users' => User::query()->where('is_active', true)->count(),
                'inactive_users' => User::query()->where('is_active', false)->count(),
                'classes' => ClassRoom::query()->where('is_active', true)->count(),
                'pending_identity_verifications' => IdentityVerification::query()
                    ->where('status', 'pending_verification')
                    ->count(),
            ],
            'security' => [
                'events_last_24h' => SecurityEvent::query()
                    ->where('created_at', '>=', now()->subDay())
                    ->count(),
                'high_severity_last_7d' => SecurityEvent::query()
                    ->whereIn('severity', ['high', 'critical'])
                    ->where('created_at', '>=', now()->subDays(7))
                    ->count(),
            ],
            'note' => 'Dashboard Super Admin sengaja tidak menampilkan daftar atau isi pengaduan.',
        ];
    }

    public function forIdentityVerifier(User $user): array
    {
        return [
            'role' => 'identity_verifier',
            'identity_verifications' => [
                'pending' => IdentityVerification::query()
                    ->where('status', 'pending_verification')
                    ->count(),
            ],
        ];
    }

    // ------------------------------------------------------------------ helper

    private function reportCard(Report $report): array
    {
        // Kartu laporan TIDAK memuat judul atau isi untuk peran yang tidak
        // berhak; pemanggilnya adalah pelapor/penangan sehingga metadata
        // dipakai, bukan konten.
        return [
            'public_ref' => $report->public_ref,
            'status' => $report->status?->value,
            'category_id' => $report->category_id,
            'routing_mode' => $report->routing_mode?->value,
            'last_activity_at' => $report->last_activity_at?->toIso8601String(),
            'created_at' => $report->created_at?->toIso8601String(),
        ];
    }

    private function recentAspirations(int|array $classIds): array
    {
        $ids = is_array($classIds) ? $classIds : [$classIds];

        return Aspiration::query()
            ->whereIn('class_id', $ids)
            ->visible()
            ->latest('last_activity_at')
            ->limit(5)
            ->get()
            ->map(fn (Aspiration $a) => [
                'id' => $a->id,
                'title' => $a->title,
                'supports_count' => $a->supports_count,
                'comments_count' => $a->comments_count,
                'last_activity_at' => $a->last_activity_at?->toIso8601String(),
            ])
            ->all();
    }

    private function membershipSummary(User $user): ?array
    {
        $membership = ClassMembership::query()
            ->where('user_id', $user->id)
            ->with('classRoom:id,code,name')
            ->latest()
            ->first();

        if ($membership === null) {
            return null;
        }

        return [
            'status' => $membership->status->value,
            'class' => $membership->classRoom?->only(['id', 'code', 'name']),
        ];
    }
}
