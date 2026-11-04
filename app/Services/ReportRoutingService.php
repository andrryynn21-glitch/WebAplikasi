<?php

namespace App\Services;

use App\Enums\AssignmentType;
use App\Enums\NotificationType;
use App\Enums\ReportSensitivity;
use App\Enums\ReportStatus;
use App\Enums\RoutingMode;
use App\Models\Report;
use App\Models\ReportAssignment;
use App\Models\ReportPrivateDetail;
use App\Models\ReportStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Hybrid routing.
 *
 * Rutin  -> otomatis ke ketua kelas aktif (bila tidak berkonflik).
 * Sensitif / ambigu / berkonflik -> manual (needs_review), menunggu
 * peninjauan dosen koordinator.
 *
 * Pihak yang disebut dalam pengaduan tidak pernah menjadi penangan
 * otomatis. Jika ketua kelas disebut, laporan tidak dirutekan kepadanya.
 */
class ReportRoutingService
{
    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    public function route(Report $report): Report
    {
        $report->loadMissing(['category', 'privateDetails', 'classRoom']);

        $requiresHuman = $this->requiresHumanReview($report);

        return DB::transaction(function () use ($report, $requiresHuman) {
            if ($requiresHuman) {
                $report->forceFill([
                    'routing_mode' => RoutingMode::Manual,
                    'status' => $report->status === ReportStatus::Submitted
                        ? ReportStatus::NeedsReview
                        : $report->status,
                ])->save();

                ReportStatusHistory::create([
                    'report_id' => $report->id,
                    'from_status' => ReportStatus::Submitted->value,
                    'to_status' => ReportStatus::NeedsReview->value,
                    'actor_id' => null,
                    'reason' => 'Dirutekan untuk peninjauan manusia: kategori sensitif, ambigu, atau berpotensi konflik.',
                    'source' => 'routing',
                ]);

                $this->notifyReviewers($report);

                return $report->fresh();
            }

            $leader = $this->selectClassLeader($report);

            if ($leader === null) {
                $report->forceFill([
                    'routing_mode' => RoutingMode::Manual,
                    'status' => ReportStatus::NeedsReview,
                ])->save();

                ReportStatusHistory::create([
                    'report_id' => $report->id,
                    'from_status' => ReportStatus::Submitted->value,
                    'to_status' => ReportStatus::NeedsReview->value,
                    'actor_id' => null,
                    'reason' => 'Belum ada ketua kelas aktif yang dapat menangani; dialihkan ke peninjauan.',
                    'source' => 'routing',
                ]);

                $this->notifyReviewers($report);

                return $report->fresh();
            }

            $report->forceFill([
                'routing_mode' => RoutingMode::Auto,
                'status' => ReportStatus::UnderReview,
                'current_handler_id' => $leader->id,
            ])->save();

            ReportAssignment::create([
                'report_id' => $report->id,
                'assignee_id' => $leader->id,
                'assigned_by' => null,
                'assignment_type' => AssignmentType::Primary,
                'reason' => 'Routing otomatis kategori rutin.',
                'assigned_at' => now(),
            ]);

            ReportStatusHistory::create([
                'report_id' => $report->id,
                'from_status' => ReportStatus::Submitted->value,
                'to_status' => ReportStatus::UnderReview->value,
                'actor_id' => null,
                'reason' => 'Dirutekan otomatis ke ketua kelas.',
                'source' => 'routing',
            ]);

            $this->notifications->reportEvent(
                $leader,
                $report,
                NotificationType::ReportAssigned,
                'Pengaduan baru ditugaskan',
                'Sebuah pengaduan rutin masuk ke kelas Anda dan perlu ditinjau.',
            );

            return $report->fresh();
        });
    }

    /**
     * Kriteria peninjauan manusia:
     * - kategori ditandai sensitif atau wajib review,
     * - pihak yang terlibat menyebut dosen pengampu kelas,
     * - tidak ada ketua kelas aktif (ditangani pemanggil),
     * - deskripsi terlalu ambigu untuk routing (judul/deskripsi sangat pendek).
     */
    public function requiresHumanReview(Report $report): bool
    {
        $category = $report->category;

        if ($category?->is_sensitive || $category?->requires_human_review) {
            return true;
        }

        if ($category?->code?->isSensitiveByDefault()) {
            return true;
        }

        if ($report->sensitivity === ReportSensitivity::Sensitive) {
            return true;
        }

        $mentionsLecturer = $this->involvedPartiesMentionLecturer($report);

        if ($mentionsLecturer) {
            return true;
        }

        $detail = $report->privateDetails;

        if ($detail instanceof ReportPrivateDetail && mb_strlen(trim($detail->description)) < 30) {
            return true;
        }

        return false;
    }

    /**
     * Ketua kelas aktif yang layak: bukan pelapor, tidak disebut sebagai
     * pihak terlibat, dan belum punya konflik terdaftar.
     */
    private function selectClassLeader(Report $report): ?User
    {
        $leader = $report->classRoom
            ->administratorAssignments()
            ->where('starts_at', '<=', now())
            ->whereNull('ends_at')
            ->with('user')
            ->first()
            ?->user;

        if ($leader === null || ! $leader->isClassLeaderOf($report->class_id)
            || ! $leader->hasPermission('reports.update_status') || $leader->id === $report->reporter_id) {
            return null;
        }

        if ($report->hasConflictFor($leader)
            || app(ReportWorkflowService::class)->isSubjectOfReport($report, $leader)) {
            return null;
        }

        if ($this->involvedPartiesMentionLecturer($report)) {
            // Konflik dosen tidak otomatis mendiskualifikasi ketua kelas,
            // tetapi laporan dengan pihak dosen sudah dialihkan ke peninjauan.
            return null;
        }

        return $leader;
    }

    private function involvedPartiesMentionLecturer(Report $report): bool
    {
        $parties = $report->privateDetails?->involved_parties;

        if (! is_array($parties)) {
            return false;
        }

        $lecturerNames = $report->classRoom
            ->lecturerAssignments()
            ->whereNull('ends_at')
            ->with('lecturer:id,name')
            ->get()
            ->pluck('lecturer.name')
            ->filter()
            ->map(fn (string $name) => mb_strtolower(trim($name)));

        foreach ($parties as $party) {
            $text = is_string($party) ? $party : (is_array($party) ? json_encode($party) : '');

            if ($text === '') {
                continue;
            }

            $lower = mb_strtolower($text);

            if (in_array($lower, $lecturerNames->all(), true)) {
                return true;
            }

            if (str_contains($lower, 'dosen') && $lecturerNames->isNotEmpty()) {
                return true;
            }
        }

        return false;
    }

    private function notifyReviewers(Report $report): void
    {
        $this->notifications->toClassLecturers(
            $report->classRoom,
            NotificationType::ReportStatusChanged,
            'Pengaduan memerlukan peninjauan',
            'Ada pengaduan yang memerlukan peninjauan dosen koordinator.',
            ['report_ref' => $report->public_ref],
        );

        // Notifikasi tingkat admin kelas tetap dikirim tanpa isi laporan.
        $this->notifications->toClassLeaders(
            $report->classRoom,
            NotificationType::SecurityNotice,
            'Pengaduan baru masuk ke antrean peninjauan',
            'Sebuah pengaduan baru menunggu peninjauan dosen koordinator.',
            ['report_ref' => $report->public_ref],
        );
    }
}
