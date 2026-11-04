<?php

namespace Tests\Feature;

use App\Enums\AppealStatus;
use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Models\Appeal;
use App\Models\Report;
use App\Services\AppealService;
use App\Services\ReportWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\Domain;
use Tests\TestCase;

/**
 * Skenario wajib #9, #10, #11, #17.
 */
class ReportWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Domain::seedCatalog();
    }

    private function routineReport($reporter, $class): Report
    {
        $this->actingAs($reporter, 'sanctum');

        $response = $this->postJson('/api/v1/reports', [
            'category_id' => Domain::category('facilities')->id,
            'title' => 'Proyektor kelas tidak berfungsi',
            'description' => 'Proyektor di ruang kelas mati total sejak dua minggu lalu sehingga presentasi terganggu.',
        ])->assertCreated();

        return Report::query()->where('public_ref', $response->json('data.public_ref'))->firstOrFail();
    }

    private function context(): array
    {
        $class = Domain::classRoom();
        $leader = Domain::user(UserRole::ClassLeader, ['nim' => 'L1']);
        Domain::leader($leader, $class);
        $reporter = Domain::user(UserRole::Student, ['nim' => 'R1']);
        Domain::member($reporter, $class);

        return [$class, $leader, $reporter];
    }

    /** #9 Transisi status ilegal ditolak. */
    public function test_illegal_transition_is_rejected(): void
    {
        [$class, $leader, $reporter] = $this->context();
        $report = $this->routineReport($reporter, $class);

        $this->assertSame(ReportStatus::UnderReview, $report->status);

        $this->actingAs($leader, 'sanctum')
            ->patchJson('/api/v1/reports/'.$report->public_ref.'/status', [
                'status' => 'resolved',
                'resolution_summary' => 'Langsung selesai tanpa tahapan.',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        // Riwayat tetap utuh: hanya submitted dan under_review.
        $this->assertSame(2, $report->statusHistories()->count());
        $this->assertSame(ReportStatus::UnderReview, $report->fresh()->status);
    }

    /** #10 Penolakan tanpa alasan ditolak. */
    public function test_rejection_without_reason_is_rejected(): void
    {
        [$class, $leader, $reporter] = $this->context();
        $report = $this->routineReport($reporter, $class);

        $this->actingAs($leader, 'sanctum')
            ->patchJson('/api/v1/reports/'.$report->public_ref.'/status', [
                'status' => 'rejected',
            ])
            ->assertStatus(422);

        $this->assertSame(ReportStatus::UnderReview, $report->fresh()->status);

        // Dengan alasan, penolakan berhasil dan tercatat.
        $this->actingAs($leader, 'sanctum')
            ->patchJson('/api/v1/reports/'.$report->public_ref.'/status', [
                'status' => 'rejected',
                'reason' => 'Pengaduan duplikat dengan laporan sebelumnya.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $report->refresh();
        $this->assertSame('Pengaduan duplikat dengan laporan sebelumnya.', $report->rejection_reason);
        $this->assertSame(3, $report->statusHistories()->count());
    }

    public function test_resolution_requires_summary(): void
    {
        [$class, $leader, $reporter] = $this->context();
        $report = $this->routineReport($reporter, $class);

        $workflow = app(ReportWorkflowService::class);

        $workflow->transition($report, ReportStatus::InProgress, $leader, []);
        $report->refresh();
        $workflow->transition($report, ReportStatus::ResolutionProposed, $leader, [
            'reason' => 'Perbaikan proyektor dijadwalkan.',
        ]);
        $report->refresh();

        try {
            $workflow->transition($report, ReportStatus::Resolved, $leader, []);
            $this->fail('Penyelesaian tanpa ringkasan seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('resolution_summary', $e->errors());
        }

        $workflow->transition($report, ReportStatus::Resolved, $leader, [
            'resolution_summary' => 'Proyektor sudah diganti dan diuji.',
        ]);

        $this->assertSame(ReportStatus::Resolved, $report->fresh()->status);
        $this->assertSame('Proyektor sudah diganti dan diuji.', $report->fresh()->resolution_summary);
    }

    public function test_reporter_cannot_change_own_status(): void
    {
        [$class, $leader, $reporter] = $this->context();
        $report = $this->routineReport($reporter, $class);

        $this->actingAs($reporter, 'sanctum')
            ->patchJson('/api/v1/reports/'.$report->public_ref.'/status', [
                'status' => 'resolved',
                'resolution_summary' => 'Saya tandai selesai sendiri.',
            ])
            ->assertForbidden();
    }

    /** #11 Banding ditangani reviewer berbeda. */
    public function test_appeal_must_be_reviewed_by_a_different_reviewer(): void
    {
        $class = Domain::classRoom();
        $leader = Domain::user(UserRole::ClassLeader, ['nim' => 'L1']);
        Domain::leader($leader, $class);
        $reporter = Domain::user(UserRole::Student, ['nim' => 'R1']);
        Domain::member($reporter, $class);
        $lecturer = Domain::user(UserRole::LecturerCoordinator);
        Domain::lecturer($lecturer, $class);

        $report = $this->routineReport($reporter, $class);

        app(ReportWorkflowService::class)->transition($report->fresh(), ReportStatus::Rejected, $leader, [
            'reason' => 'Tidak ada bukti pendukung yang cukup.',
        ]);

        $report->refresh();
        $this->assertSame(ReportStatus::Rejected, $report->status);

        // Pelapor mengajukan banding.
        $this->actingAs($reporter, 'sanctum')
            ->postJson('/api/v1/reports/'.$report->public_ref.'/appeals', [
                'reason' => 'Saya memiliki foto dan rekaman sebagai bukti tambahan yang belum diperiksa.',
            ])
            ->assertCreated();

        $appeal = Appeal::query()->where('report_id', $report->id)->firstOrFail();
        $this->assertSame(AppealStatus::Pending, $appeal->status);
        $this->assertSame(ReportStatus::AppealPending, $report->fresh()->status);

        $service = app(AppealService::class);

        // Penangan awal (yang memutuskan) tidak memenuhi syarat.
        try {
            $service->assignReviewer($appeal, $leader, $lecturer);
            $this->fail('Penangan awal seharusnya tidak boleh menjadi peninjau banding.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reviewer_id', $e->errors());
        }

        // Dosen yang belum pernah menangani diterima sebagai peninjau.
        $service->assignReviewer($appeal, $lecturer, $lecturer);
        $this->assertSame($lecturer->id, $appeal->fresh()->reviewer_id);

        $decided = $service->decide(
            $appeal->fresh(),
            $lecturer,
            AppealStatus::Accepted,
            'Bukti tambahan valid, laporan dibuka kembali.',
        );

        $this->assertSame(AppealStatus::Accepted, $decided->status);
        $this->assertSame(ReportStatus::Reopened, $report->fresh()->status);

        // Keputusan tercatat di riwayat append-only.
        $this->assertDatabaseHas('report_status_histories', [
            'report_id' => $report->id,
            'to_status' => ReportStatus::Reopened->value,
            'actor_id' => $lecturer->id,
        ]);
    }

    /** #17 Konsistensi transaksi ketika gagal. */
    public function test_failed_transition_leaves_no_partial_state(): void
    {
        [$class, $leader, $reporter] = $this->context();
        $report = $this->routineReport($reporter, $class);

        $historyBefore = $report->statusHistories()->count();
        $statusBefore = $report->fresh()->status;

        try {
            app(ReportWorkflowService::class)->transition($report, ReportStatus::Resolved, $leader, [
                'resolution_summary' => 'Selesai.',
            ]);
            $this->fail('Transisi ilegal seharusnya melempar exception.');
        } catch (ValidationException) {
            // diharapkan
        }

        $report->refresh();
        $this->assertSame($statusBefore, $report->status);
        $this->assertSame($historyBefore, $report->statusHistories()->count());
        $this->assertNull($report->resolved_at);
        $this->assertNull($report->resolution_summary);
    }

    public function test_status_history_is_append_only(): void
    {
        [$class, $leader, $reporter] = $this->context();
        $report = $this->routineReport($reporter, $class);

        $history = $report->statusHistories()->first();

        $this->expectException(\LogicException::class);
        $history->update(['reason' => 'diubah diam-diam']);
    }
}
