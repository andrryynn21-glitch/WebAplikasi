<?php

namespace App\Services;

use App\Enums\MembershipStatus;
use App\Enums\ReportStatus;
use App\Models\Aspiration;
use App\Models\AspirationComment;
use App\Models\ClassMembership;
use App\Models\ContentFlag;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Statistik penanganan. Selalu dibatasi kelas yang boleh diakses pengguna.
 *
 * Privasi: agregasi hanya menghitung metadata (status, kategori, usia).
 * Tidak ada judul, isi, atau identitas pelapor pada output statistik.
 */
class StatisticsService
{
    /**
     * Ringkasan untuk satu kelas atau seluruh kelas yang dapat diakses.
     *
     * @param  array<int, int>|null  $classIds
     * @return array<string, mixed>
     */
    public function summary(User $viewer, ?int $classId = null): array
    {
        $allowed = $this->scopeClasses($viewer, $classId);

        $base = Report::query()->whereIn('class_id', $allowed)->visibleTo($viewer);

        $byStatus = (clone $base)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $byCategory = (clone $base)
            ->join('report_categories', 'report_categories.id', '=', 'reports.category_id')
            ->select('report_categories.code', DB::raw('count(*) as total'))
            ->groupBy('report_categories.code')
            ->pluck('total', 'report_categories.code');

        $open = (clone $base)->open()->count();
        $resolved = (clone $base)->where('status', ReportStatus::Resolved->value)->count();
        $rejected = (clone $base)->where('status', ReportStatus::Rejected->value)->count();

        $avgResolutionHours = (clone $base)
            ->whereNotNull('resolved_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at)) as minutes')
            ->value('minutes');

        $slaBreaches = (clone $base)
            ->open()
            ->where('created_at', '<', Carbon::now()->subDays(7))
            ->count();

        return [
            'class_ids' => $allowed,
            'total_reports' => (clone $base)->count(),
            'open_reports' => $open,
            'resolved_reports' => $resolved,
            'rejected_reports' => $rejected,
            'by_status' => $byStatus,
            'by_category' => $byCategory,
            'average_resolution_hours' => $avgResolutionHours !== null
                ? round(((float) $avgResolutionHours) / 60, 1)
                : null,
            'open_older_than_7_days' => $slaBreaches,
        ];
    }

    /**
     * Statistik aspirasi per kelas.
     *
     * @param  array<int, int>|null  $classIds
     * @return array<string, mixed>
     */
    public function aspirationSummary(User $viewer, ?int $classId = null): array
    {
        $allowed = $this->scopeClasses($viewer, $classId);

        $base = DB::table('aspirations')->whereIn('class_id', $allowed);

        return [
            'total_aspirations' => (clone $base)->count(),
            'total_supports' => (int) (clone $base)->sum('supports_count'),
            'total_comments' => (int) (clone $base)->sum('comments_count'),
            'open_flags' => ContentFlag::query()
                ->where('status', 'open')
                ->whereHasMorph('flaggable', [Aspiration::class, AspirationComment::class], function ($query, $type) use ($allowed) {
                    if ($type === Aspiration::class) {
                        $query->whereIn('class_id', $allowed);
                    } else {
                        $query->whereHas('aspiration', fn ($q) => $q->whereIn('class_id', $allowed));
                    }
                })
                ->count(),
        ];
    }

    /**
     * Batasi kelas yang boleh dihitung. Mahasiswa biasa hanya melihat
     * statistik kelasnya sendiri; dosen hanya kelas yang ditugaskan.
     *
     * @return array<int, int>
     */
    public function scopeClasses(User $viewer, ?int $classId): array
    {
        $allowed = $viewer->managedClassIds();

        if ($classId !== null) {
            if (! in_array($classId, $allowed, true)) {
                return [];
            }

            return [$classId];
        }

        return $allowed;
    }

    public function populationByClass(int $classId): array
    {
        return [
            'active_members' => ClassMembership::query()
                ->where('class_id', $classId)
                ->where('status', MembershipStatus::Active->value)
                ->count(),
            'pending_members' => ClassMembership::query()
                ->where('class_id', $classId)
                ->where('status', MembershipStatus::Pending->value)
                ->count(),
        ];
    }
}
