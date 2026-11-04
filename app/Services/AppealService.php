<?php

namespace App\Services;

use App\Enums\AppealStatus;
use App\Enums\AssignmentType;
use App\Enums\AuditAction;
use App\Enums\NotificationType;
use App\Enums\ReportStatus;
use App\Models\Appeal;
use App\Models\Report;
use App\Models\ReportAssignment;
use App\Models\ReportStatusHistory;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Banding.
 *
 * Aturan:
 * - Banding adalah record terpisah (bukan status-only).
 * - Hanya pelapor yang dapat mengajukan, dan hanya untuk laporan
 *   rejected/resolved.
 * - Peninjau banding harus BERBEDA dari semua pihak yang pernah
 *   memutuskan/menangani laporan dan tidak berkonflik.
 */
class AppealService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly NotificationService $notifications,
        private readonly ReportWorkflowService $workflow,
    ) {}

    public function submit(Report $report, User $reporter, string $reason): Appeal
    {
        if ($report->reporter_id !== $reporter->id) {
            throw ValidationException::withMessages([
                'reason' => 'Hanya pelapor yang dapat mengajukan banding.',
            ]);
        }

        if (! in_array($report->status, [ReportStatus::Rejected, ReportStatus::Resolved], true)) {
            throw ValidationException::withMessages([
                'reason' => 'Banding hanya dapat diajukan untuk pengaduan yang ditolak atau sudah selesai.',
            ]);
        }

        if ($report->openAppeal()->exists()) {
            throw ValidationException::withMessages([
                'reason' => 'Masih ada banding yang sedang diproses untuk pengaduan ini.',
            ]);
        }

        return DB::transaction(function () use ($report, $reporter, $reason) {
            $locked = Report::query()->lockForUpdate()->findOrFail($report->id);

            Gate::forUser($reporter)->authorize('submitAppeal', $locked);

            if ($locked->openAppeal()->exists()) {
                throw ValidationException::withMessages(['reason' => 'Masih ada banding yang sedang diproses.']);
            }

            if (trim($reason) === '') {
                throw ValidationException::withMessages(['reason' => 'Alasan banding wajib diisi.']);
            }

            $appeal = Appeal::create([
                'report_id' => $locked->id,
                'submitted_by' => $reporter->id,
                'reason' => $reason,
                'status' => AppealStatus::Pending,
                'report_status_at_submission' => $locked->status?->value,
            ]);

            $previous = $locked->status;

            $locked->forceFill([
                'status' => ReportStatus::AppealPending,
                'last_activity_at' => now(),
            ])->save();

            ReportStatusHistory::create([
                'report_id' => $locked->id,
                'from_status' => $previous?->value,
                'to_status' => ReportStatus::AppealPending->value,
                'actor_id' => $reporter->id,
                'reason' => 'Banding diajukan.',
                'source' => 'api',
            ]);

            $reviewers = $this->eligibleReviewers($locked);

            $this->notifications->toClassLecturers(
                $locked->classRoom,
                NotificationType::AppealSubmitted,
                'Banding baru diajukan',
                'Sebuah banding menunggu peninjauan oleh peninjau yang tidak berkonflik.',
                ['report_ref' => $locked->public_ref, 'eligible_reviewers' => $reviewers->count()],
            );

            return $appeal;
        });
    }

    /**
     * Tetapkan peninjau. Menolak peninjau yang pernah menangani/memutuskan.
     */
    public function assignReviewer(Appeal $appeal, User $reviewer, User $actor): Appeal
    {
        return DB::transaction(function () use ($appeal, $reviewer, $actor) {
            $locked = Appeal::query()->lockForUpdate()->findOrFail($appeal->id);
            $report = Report::query()->lockForUpdate()->findOrFail($locked->report_id);
            $locked->setRelation('report', $report);

            Gate::forUser($actor)->authorize('reviewAppeal', $report);

            if (! $locked->status->isOpen()) {
                throw ValidationException::withMessages(['appeal' => 'Banding ini sudah diputuskan.']);
            }

            $this->assertEligible($locked, $reviewer, $actor);

            $locked->forceFill([
                'reviewer_id' => $reviewer->id,
                'status' => AppealStatus::UnderReview,
            ])->save();

            ReportAssignment::query()->firstOrCreate([
                'report_id' => $report->id,
                'assignee_id' => $reviewer->id,
                'assignment_type' => AssignmentType::AppealReviewer->value,
                'revoked_at' => null,
            ], [
                'assigned_by' => $actor->id,
                'assigned_at' => now(),
                'reason' => 'Peninjauan banding.',
            ]);

            $this->audit->record(AuditAction::ReportAssigned, $actor, $report, $report->class_id, [
                'assignee_id' => $reviewer->id,
                'type' => AssignmentType::AppealReviewer->value,
            ]);

            return $locked;
        });
    }

    public function decide(Appeal $appeal, User $decider, AppealStatus $decision, string $note): Appeal
    {
        if (! in_array($decision, [AppealStatus::Accepted, AppealStatus::Rejected], true)) {
            throw ValidationException::withMessages([
                'status' => 'Keputusan banding hanya accepted atau rejected.',
            ]);
        }

        if (trim($note) === '') {
            throw ValidationException::withMessages([
                'decision_note' => 'Catatan keputusan wajib diisi.',
            ]);
        }

        return DB::transaction(function () use ($appeal, $decider, $decision, $note) {
            $locked = Appeal::query()->lockForUpdate()->findOrFail($appeal->id);
            $report = Report::query()->lockForUpdate()->findOrFail($locked->report_id);
            $locked->setRelation('report', $report);

            Gate::forUser($decider)->authorize('reviewAppeal', $report);

            if ($locked->reviewer_id !== $decider->id) {
                throw ValidationException::withMessages(['reviewer_id' => 'Tetapkan peninjau sebelum memutuskan banding.']);
            }

            $this->assertEligible($locked, $decider, $decider);

            if (! $locked->status->isOpen()) {
                throw ValidationException::withMessages([
                    'appeal' => 'Banding ini sudah diputuskan.',
                ]);
            }

            $locked->forceFill([
                'status' => $decision,
                'decided_by' => $decider->id,
                'decision_note' => $note,
                'decided_at' => now(),
                'reviewer_id' => $locked->reviewer_id ?? $decider->id,
            ])->save();

            $previous = $report->status;

            $nextStatus = $decision === AppealStatus::Accepted
                ? ReportStatus::Reopened
                : $report->status;

            // Banding diterima = laporan dibuka kembali untuk penanganan ulang.
            if ($decision === AppealStatus::Accepted) {
                $nextStatus = ReportStatus::Reopened;
            } elseif ($decision === AppealStatus::Rejected) {
                $nextStatus = $locked->report_status_at_submission === ReportStatus::Rejected->value
                    ? ReportStatus::Rejected
                    : ReportStatus::Resolved;
            }

            if ($report->status !== $nextStatus) {
                $report->forceFill([
                    'status' => $nextStatus,
                    'closed_at' => $nextStatus->isTerminal() ? now() : null,
                    'resolved_at' => $nextStatus === ReportStatus::Reopened ? null : $report->resolved_at,
                    'last_activity_at' => now(),
                ])->save();

                ReportStatusHistory::create([
                    'report_id' => $report->id,
                    'from_status' => $previous?->value,
                    'to_status' => $nextStatus->value,
                    'actor_id' => $decider->id,
                    'reason' => 'Keputusan banding: '.$decision->label().'.',
                    'source' => 'api',
                ]);
            }

            $report->activeAssignments()->where('assignment_type', AssignmentType::AppealReviewer->value)
                ->where('assignee_id', $decider->id)->update(['revoked_at' => now()]);

            $this->audit->record(AuditAction::AppealDecided, $decider, $locked, $report->class_id, [
                'appeal_id' => $locked->id,
                'decision' => $decision->value,
            ]);

            $this->notifications->reportEvent(
                $report->reporter,
                $report,
                NotificationType::AppealDecided,
                'Keputusan banding',
                'Banding Anda diputuskan: '.$decision->label().'.',
            );

            return $locked;
        });
    }

    /**
     * Peninjau yang memenuhi syarat: permission appeal_review, akses kelas,
     * bukan pelapor, bukan mantan penangan, bukan peninjau sebelumnya,
     * dan bebas konflik.
     *
     * @return Collection<int, User>
     */
    public function eligibleReviewers(Report $report): Collection
    {
        $excluded = $report->assignments()->whereIn('assignment_type', ['primary', 'secondary'])->pluck('assignee_id')
            ->merge($report->statusHistories()->whereIn('to_status', ['rejected', 'resolved'])->pluck('actor_id'))
            ->merge($report->appeals()->whereNotNull('decided_by')->pluck('decided_by'))
            ->push($report->reporter_id)
            ->unique();

        return User::query()
            ->where('is_active', true)
            ->whereNotIn('id', $excluded)
            ->whereHas('role.permissions', fn ($q) => $q->where('key', 'reports.appeal_review'))
            ->whereHas('lecturerAssignments', fn ($q) => $q->where('class_id', $report->class_id)->whereNull('ends_at'))
            ->get()
            ->reject(fn (User $user) => ! $user->isLecturerOf($report->class_id)
                || $report->hasConflictFor($user) || $this->workflow->isSubjectOfReport($report, $user))
            ->values();
    }

    private function assertEligible(Appeal $appeal, User $candidate, User $actor): void
    {
        $report = $appeal->report;

        if ($candidate->id === $appeal->submitted_by) {
            throw ValidationException::withMessages([
                'reviewer_id' => 'Pelapor tidak dapat meninjau bandingnya sendiri.',
            ]);
        }

        if ($candidate->id === $appeal->decided_by) {
            throw ValidationException::withMessages([
                'reviewer_id' => 'Peninjau harus berbeda dari pemutus sebelumnya.',
            ]);
        }

        if ($report->assignments()->where('assignee_id', $candidate->id)
            ->whereIn('assignment_type', ['primary', 'secondary'])->exists()
            || $report->statusHistories()->where('actor_id', $candidate->id)->whereIn('to_status', ['rejected', 'resolved'])->exists()
            || $report->appeals()->where('decided_by', $candidate->id)->where('id', '!=', $appeal->id)->exists()) {
            throw ValidationException::withMessages([
                'reviewer_id' => 'Peninjau harus berbeda dari penangan yang pernah ditugaskan pada laporan ini.',
            ]);
        }

        if ($report->hasConflictFor($candidate) || $this->workflow->isSubjectOfReport($report, $candidate)) {
            throw ValidationException::withMessages([
                'reviewer_id' => 'Peninjau tercatat sebagai pihak berkepentingan pada laporan ini.',
            ]);
        }

        if (! $candidate->is_active || ! $candidate->hasPermission('reports.appeal_review')) {
            throw ValidationException::withMessages([
                'reviewer_id' => 'Peninjau tidak memiliki kewenangan meninjau banding.',
            ]);
        }

        if (! $candidate->isLecturerOf($report->class_id)) {
            throw ValidationException::withMessages([
                'reviewer_id' => 'Peninjau tidak ditugaskan pada kelas laporan ini.',
            ]);
        }

        if ($appeal->reviewer_id !== null && $appeal->reviewer_id !== $candidate->id) {
            throw ValidationException::withMessages([
                'reviewer_id' => 'Banding sudah memiliki peninjau.',
            ]);
        }
    }
}
