<?php

namespace Tests\Feature;

use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Models\Report;
use App\Services\AppealService;
use App\Services\ReportWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Domain;
use Tests\TestCase;

class FinalReportAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Domain::seedCatalog();
    }

    private function reportContext(): array
    {
        $class = Domain::classRoom();
        $leader = Domain::user(UserRole::ClassLeader);
        $assignment = Domain::leader($leader, $class);
        $student = Domain::user(UserRole::Student);
        Domain::member($student, $class);
        $response = $this->actingAs($student, 'sanctum')->postJson('/api/v1/reports', [
            'category_id' => Domain::category('facilities')->id,
            'title' => 'Audit fasilitas ruang kelas',
            'description' => 'Data fiktif audit: proyektor tidak berfungsi saat kegiatan kelas berlangsung.',
        ])->assertCreated();

        return [$class, $leader, $student, Report::where('public_ref', $response->json('data.public_ref'))->firstOrFail(), $assignment];
    }

    public function test_revoked_leader_cannot_list_other_students_report_metadata(): void
    {
        [$class, $leader, $student, $report, $assignment] = $this->reportContext();
        $assignment->update(['ends_at' => now()]);

        $this->actingAs($leader, 'sanctum')->getJson('/api/v1/reports?scope=class')
            ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.pagination.total', 0);
    }

    public function test_revoked_leader_dashboard_and_statistics_no_longer_aggregate_class_reports(): void
    {
        [$class, $leader, $student, $report, $assignment] = $this->reportContext();
        $assignment->update(['ends_at' => now()]);

        $this->actingAs($leader, 'sanctum')->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('data.reports.assigned_open', 0)
            ->assertJsonPath('data.statistics.total_reports', 0);
        $this->getJson('/api/v1/reports/statistics')->assertOk()->assertJsonPath('data.total_reports', 0);
    }

    public function test_future_class_leader_assignment_is_not_used_for_automatic_routing(): void
    {
        $class = Domain::classRoom();
        $leader = Domain::user(UserRole::ClassLeader);
        Domain::leader($leader, $class)->update(['starts_at' => now()->addDay()]);
        $student = Domain::user(UserRole::Student);
        Domain::member($student, $class);

        $response = $this->actingAs($student, 'sanctum')->postJson('/api/v1/reports', [
            'category_id' => Domain::category('facilities')->id,
            'title' => 'Belum ada ketua aktif',
            'description' => 'Data fiktif audit untuk penugasan ketua yang belum dimulai saat laporan dibuat.',
        ])->assertCreated()->assertJsonPath('data.status', 'needs_review');

        $this->assertNull(Report::where('public_ref', $response->json('data.public_ref'))->firstOrFail()->current_handler_id);
    }

    public function test_clarification_cannot_be_requested_when_state_machine_disallows_it(): void
    {
        [$class, $leader, $student, $report] = $this->reportContext();
        app(ReportWorkflowService::class)->transition($report, ReportStatus::InProgress, $leader);

        $this->actingAs($leader, 'sanctum')->postJson('/api/v1/reports/'.$report->public_ref.'/clarification-requests', [
            'body' => 'Klarifikasi ini tidak dapat dijawab dari status in_progress.',
        ])->assertForbidden();

        $this->assertDatabaseCount('report_updates', 0);
    }

    public function test_ordinary_reply_does_not_consume_a_clarification_request(): void
    {
        [$class, $leader, $student, $report] = $this->reportContext();
        $request = $this->actingAs($leader, 'sanctum')->postJson('/api/v1/reports/'.$report->public_ref.'/clarification-requests', [
            'body' => 'Mohon rincian waktu kejadian untuk penanganan.',
        ])->assertCreated()->json('data.id');

        $this->actingAs($student, 'sanctum')->postJson('/api/v1/reports/'.$report->public_ref.'/updates', [
            'body' => 'Saya akan mencari catatan waktu terlebih dahulu.', 'reply_to_id' => $request,
        ])->assertCreated();
        $this->postJson('/api/v1/reports/'.$report->public_ref.'/updates', [
            'body' => 'Kejadian berlangsung pada pukul sepuluh pagi.', 'type' => 'clarification_response', 'reply_to_id' => $request,
        ])->assertCreated();
    }

    public function test_report_detail_does_not_eager_load_unbounded_update_history(): void
    {
        [$class, $leader, $student, $report] = $this->reportContext();
        $unbounded = [];
        DB::listen(function ($query) use (&$unbounded): void {
            if (str_starts_with($query->sql, 'select')
                && str_contains($query->sql, '`report_updates`')
                && ! str_contains(strtolower($query->sql), 'limit')) {
                $unbounded[] = $query->sql;
            }
        });

        $this->actingAs($student, 'sanctum')->getJson('/api/v1/reports/'.$report->public_ref)->assertOk();
        $this->assertSame([], $unbounded, 'Detail should fetch only the bounded update feed once.');
    }

    public function test_ordinary_assignment_cannot_invalidate_an_active_appeal_reviewer(): void
    {
        [$class, $leader, $student, $report] = $this->reportContext();
        app(ReportWorkflowService::class)->transition($report, ReportStatus::Rejected, $leader, ['reason' => 'Alasan fiktif penolakan.']);
        $reviewer = Domain::user(UserRole::LecturerCoordinator);
        Domain::lecturer($reviewer, $class);
        $appeal = app(AppealService::class)->submit($report->fresh(), $student, 'Alasan fiktif untuk peninjauan independen.');
        app(AppealService::class)->assignReviewer($appeal, $reviewer, $reviewer);

        $this->actingAs($reviewer, 'sanctum')->postJson('/api/v1/reports/'.$report->public_ref.'/assignments', [
            'assignee_id' => $reviewer->id, 'reason' => 'Penugasan utama saat peninjauan banding masih berlangsung.',
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('report_assignments', [
            'report_id' => $report->id, 'assignee_id' => $reviewer->id, 'assignment_type' => 'primary',
        ]);
        $this->assertTrue($reviewer->can('reviewAppeal', $report->fresh()));
    }
}
