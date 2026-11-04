<?php

namespace App\Policies;

use App\Enums\ReportSensitivity;
use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Models\Report;
use App\Models\User;
use App\Services\ReportWorkflowService;

/**
 * Otorisasi pengaduan. Semua keputusan di sini dijalankan di backend;
 * menyembunyikan menu di frontend tidak pernah cukup.
 *
 * Super Admin sengaja TIDAK memiliki jalur melihat laporan atau isi
 * pengaduan hanya karena role-nya. Akses hanya melalui penugasan.
 */
class ReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->role !== null;
    }

    /**
     * Melihat metadata laporan (bukan isi).
     */
    public function view(User $user, Report $report): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($report->reporter_id === $user->id) {
            return true;
        }

        if (! in_array($report->class_id, $user->accessibleClassIds(), true)) {
            return false;
        }

        if ($user->hasRole(UserRole::ClassLeader) && ! $user->isClassLeaderOf($report->class_id)) {
            return false;
        }

        if ($user->hasRole(UserRole::LecturerCoordinator) && ! $user->isLecturerOf($report->class_id)) {
            return false;
        }

        if ($report->hasConflictFor($user) || $this->workflowSaysSubject($report, $user)) {
            return false;
        }

        if ($report->isAssignedTo($user) && $user->hasPermission('reports.view.private')) {
            return true;
        }

        // Ketua/dosen dapat melihat metadata laporan di kelasnya.
        if ($user->hasAnyPermission(['reports.view.class', 'reports.view.assigned'])
            && in_array($report->class_id, $user->accessibleClassIds(), true)
            && ! $report->hasConflictFor($user)) {
            return true;
        }

        return false;
    }

    /**
     * Membaca isi privat laporan.
     */
    public function viewPrivateDetails(User $user, Report $report): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($report->reporter_id === $user->id) {
            return true;
        }

        if (! $user->hasPermission('reports.view.private')) {
            return false;
        }

        if (! $report->isAssignedTo($user)) {
            return false;
        }

        if (! in_array($report->class_id, $user->accessibleClassIds(), true)
            || $report->hasConflictFor($user) || $this->workflowSaysSubject($report, $user)) {
            return false;
        }

        if ($user->hasRole(UserRole::ClassLeader)
            && (! $user->isClassLeaderOf($report->class_id)
                || $report->sensitivity === ReportSensitivity::Sensitive)) {
            return false;
        }

        if ($user->hasRole(UserRole::LecturerCoordinator) && ! $user->isLecturerOf($report->class_id)) {
            return false;
        }

        return true;
    }

    /**
     * Perubahan status hanya untuk penangan berwenang yang tidak berkonflik.
     * Pelapor tidak boleh mengubah status.
     */
    public function updateStatus(User $user, Report $report): bool
    {
        if (! $user->is_active || ! $user->hasPermission('reports.update_status')) {
            return false;
        }

        if ($report->reporter_id === $user->id) {
            return false;
        }

        if ($report->hasConflictFor($user) || $this->workflowSaysSubject($report, $user)) {
            return false;
        }

        return $this->viewPrivateDetails($user, $report)
            && $report->activeAssignments()->where('assignee_id', $user->id)
                ->whereIn('assignment_type', ['primary', 'secondary'])->exists();
    }

    /**
     * Delegasi deteksi "pihak terlapor" ke workflow service agar aturan
     * konflik hanya didefinisikan di satu tempat.
     */
    private function workflowSaysSubject(Report $report, User $user): bool
    {
        return app(ReportWorkflowService::class)->isSubjectOfReport($report, $user);
    }

    public function assign(User $user, Report $report): bool
    {
        return $user->is_active
            && $user->hasPermission('reports.assign')
            && in_array($report->class_id, $user->accessibleClassIds(), true)
            && $user->isLecturerOf($report->class_id)
            && $report->reporter_id !== $user->id
            && ! $report->hasConflictFor($user)
            && ! $this->workflowSaysSubject($report, $user);
    }

    public function requestClarification(User $user, Report $report): bool
    {
        if (! $user->hasPermission('reports.request_clarification')
            || ! in_array($report->status, [ReportStatus::UnderReview, ReportStatus::NeedsClarification], true)) {
            return false;
        }

        return $this->updateStatus($user, $report);
    }

    public function respondClarification(User $user, Report $report): bool
    {
        return $user->is_active && $user->canUseClassFeatures()
            && $report->reporter_id === $user->id
            && $report->status === ReportStatus::NeedsClarification
            && $user->hasPermission('reports.respond_clarification');
    }

    public function submitAppeal(User $user, Report $report): bool
    {
        return $user->is_active && $user->canUseClassFeatures()
            && $report->reporter_id === $user->id
            && in_array($report->status, [ReportStatus::Rejected, ReportStatus::Resolved], true)
            && $user->hasPermission('reports.appeal_submit');
    }

    public function reviewAppeal(User $user, Report $report): bool
    {
        return $user->is_active
            && $user->hasPermission('reports.appeal_review')
            && $user->isLecturerOf($report->class_id)
            && ! $report->assignments()->where('assignee_id', $user->id)
                ->whereIn('assignment_type', ['primary', 'secondary'])->exists()
            && ! $report->statusHistories()->where('actor_id', $user->id)
                ->whereIn('to_status', ['rejected', 'resolved'])->exists()
            && $report->reporter_id !== $user->id
            && in_array($report->class_id, $user->accessibleClassIds(), true)
            && ! $report->hasConflictFor($user)
            && ! $this->workflowSaysSubject($report, $user);
    }

    public function addUpdate(User $user, Report $report): bool
    {
        return $user->is_active && ($user->canUseClassFeatures() || $user->isLecturerOf($report->class_id))
            && $this->viewPrivateDetails($user, $report);
    }

    /**
     * Statistik per laporan tidak pernah diekspos ke pelapor biasa.
     */
    public function viewStatistics(User $user): bool
    {
        return $user->is_active && $user->hasPermission('reports.statistics');
    }
}
