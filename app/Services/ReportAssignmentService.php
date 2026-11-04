<?php

namespace App\Services;

use App\Enums\AssignmentType;
use App\Enums\AuditAction;
use App\Enums\NotificationType;
use App\Enums\ReportSensitivity;
use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Models\Report;
use App\Models\ReportAssignment;
use App\Models\ReportStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Penugasan penangan. Penugasan sebelumnya dicabut (revoked_at) agar
 * riwayat pengalihan tetap utuh. Penugasan aktif selalu tepat satu
 * untuk tipe primary.
 */
class ReportAssignmentService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly NotificationService $notifications,
        private readonly ReportWorkflowService $workflow,
    ) {}

    public function assign(
        Report $report,
        User $assignee,
        User $actor,
        AssignmentType $type = AssignmentType::Primary,
        ?string $reason = null,
    ): ReportAssignment {
        return DB::transaction(function () use ($report, $assignee, $actor, $type, $reason) {
            $locked = Report::query()->lockForUpdate()->findOrFail($report->id);

            Gate::forUser($actor)->authorize('assign', $locked);

            if ($locked->status === ReportStatus::AppealPending || $locked->openAppeal()->exists()) {
                throw ValidationException::withMessages([
                    'assignment_type' => 'Penugasan penangan biasa tidak dapat diubah selama banding masih berlangsung.',
                ]);
            }

            if ($type === AssignmentType::AppealReviewer) {
                throw ValidationException::withMessages([
                    'assignment_type' => 'Peninjau banding harus ditetapkan melalui alur banding.',
                ]);
            }

            $this->assertAssignable($locked, $assignee);

            $previous = $locked->activeAssignments()
                ->where('assignment_type', $type->value)
                ->lockForUpdate()
                ->get();

            if ($type === AssignmentType::Primary && $previous->contains('assignee_id', $assignee->id)) {
                throw ValidationException::withMessages([
                    'assignee_id' => 'Pengguna ini sudah menjadi penangan utama.',
                ]);
            }

            foreach ($previous as $assignment) {
                $assignment->forceFill(['revoked_at' => now()])->save();
            }

            $created = ReportAssignment::create([
                'report_id' => $locked->id,
                'assignee_id' => $assignee->id,
                'assigned_by' => $actor->id,
                'assignment_type' => $type,
                'previous_assignee_id' => $previous->first()?->assignee_id,
                'reason' => $reason,
                'assigned_at' => now(),
            ]);

            if ($type === AssignmentType::Primary) {
                $locked->forceFill(['current_handler_id' => $assignee->id, 'last_activity_at' => now()])->save();
            }

            $this->audit->record(AuditAction::ReportAssigned, $actor, $locked, $locked->class_id, [
                'assignee_id' => $assignee->id,
                'type' => $type->value,
                'previous_assignee_id' => $created->previous_assignee_id,
            ]);

            $this->notifications->reportEvent(
                $assignee,
                $locked,
                NotificationType::ReportAssigned,
                'Anda ditugaskan menangani pengaduan',
                'Pengaduan baru masuk ke daftar penanganan Anda.',
            );

            return $created;
        });
    }

    /**
     * Pengalihan penanganan harus tercatat dan penangan baru harus
     * berwenang di kelas laporan.
     */
    public function forward(Report $report, User $newHandler, User $actor, string $reason): ReportAssignment
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => 'Alasan pengalihan wajib diisi.',
            ]);
        }

        return DB::transaction(function () use ($report, $newHandler, $actor, $reason) {
            $locked = Report::query()->lockForUpdate()->findOrFail($report->id);
            Gate::forUser($actor)->authorize('assign', $locked);
            $from = $locked->status;

            if (! $from?->canTransitionTo(ReportStatus::Forwarded)) {
                throw ValidationException::withMessages([
                    'status' => 'Status pengaduan tidak mengizinkan pengalihan.',
                ]);
            }

            $assignment = $this->assign($locked, $newHandler, $actor, AssignmentType::Primary, $reason);
            $locked->forceFill(['status' => ReportStatus::Forwarded, 'last_activity_at' => now()])->save();

            ReportStatusHistory::create([
                'report_id' => $locked->id,
                'from_status' => $from->value,
                'to_status' => ReportStatus::Forwarded->value,
                'actor_id' => $actor->id,
                'reason' => $reason,
                'source' => 'api',
            ]);

            $this->audit->record(AuditAction::ReportForwarded, $actor, $locked, $locked->class_id);

            return $assignment;
        });
    }

    private function assertAssignable(Report $report, User $assignee): void
    {
        if (! $assignee->is_active || ! $assignee->hasPermission('reports.update_status')
            || $assignee->id === $report->reporter_id) {
            throw ValidationException::withMessages([
                'assignee_id' => 'Pengguna tujuan tidak memiliki kewenangan menangani pengaduan.',
            ]);
        }

        if (! in_array($report->class_id, $assignee->accessibleClassIds(), true)) {
            throw ValidationException::withMessages([
                'assignee_id' => 'Pengguna tujuan tidak memiliki akses ke kelas pengaduan ini.',
            ]);
        }

        if ($assignee->hasRole(UserRole::ClassLeader)
            && ($report->sensitivity === ReportSensitivity::Sensitive
                || ! $assignee->isClassLeaderOf($report->class_id))) {
            throw ValidationException::withMessages([
                'assignee_id' => 'Pengaduan ini berada di luar kewenangan ketua kelas.',
            ]);
        }

        if ($report->hasConflictFor($assignee) || $this->workflow->isSubjectOfReport($report, $assignee)) {
            throw ValidationException::withMessages([
                'assignee_id' => 'Pengguna tujuan tercatat sebagai pihak berkepentingan pada pengaduan ini.',
            ]);
        }
    }
}
