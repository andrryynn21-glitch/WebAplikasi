<?php

namespace Tests\Feature;

use App\Enums\AssignmentType;
use App\Enums\AuditAction;
use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Models\AcademicTerm;
use App\Models\AppNotification;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Report;
use App\Models\User;
use App\Services\ReportWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\Domain;
use Tests\TestCase;

class ReportPrivacyRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Domain::seedCatalog();
    }

    private function context(string $category = 'facilities'): array
    {
        $class = Domain::classRoom();
        $leader = Domain::user(UserRole::ClassLeader, ['nim' => 'LEADER', 'name' => 'Ketua Aktif']);
        Domain::leader($leader, $class);
        $reporter = Domain::user(UserRole::Student, ['nim' => 'REPORTER']);
        Domain::member($reporter, $class);
        $response = $this->actingAs($reporter, 'sanctum')->postJson('/api/v1/reports', [
            'category_id' => Domain::category($category)->id,
            'title' => 'RAHASIA judul pengaduan mahasiswa',
            'description' => 'Deskripsi pengaduan privat cukup panjang dan tidak boleh muncul kepada mahasiswa lain.',
        ])->assertCreated();
        $report = Report::query()->where('public_ref', $response->json('data.public_ref'))->firstOrFail();

        return [$class, $leader, $reporter, $report];
    }

    private function assignLecturer(Report $report): User
    {
        $lecturer = Domain::user(UserRole::LecturerCoordinator);
        Domain::lecturer($lecturer, $report->classRoom);
        $this->actingAs($lecturer, 'sanctum')->postJson('/api/v1/reports/'.$report->public_ref.'/assignments', [
            'assignee_id' => $lecturer->id,
            'reason' => 'Peninjauan oleh dosen koordinator kelas.',
        ])->assertCreated();

        return $lecturer;
    }

    public function test_unassigned_metadata_does_not_expose_title_and_unknown_reference_is_404(): void
    {
        [$class, $leader, $reporter, $report] = $this->context('lecturer_conduct');

        $this->actingAs($leader, 'sanctum')->getJson('/api/v1/reports/'.$report->public_ref)
            ->assertOk()->assertJsonPath('data.title', null)->assertJsonPath('data.private_details', null);
        $this->getJson('/api/v1/reports?scope=class')->assertOk()->assertJsonPath('data.0.title', null);
        $this->getJson('/api/v1/reports/PGD-NOT-FOUND')->assertNotFound();
    }

    public function test_lecturer_from_another_class_cannot_be_assigned(): void
    {
        [$class, $leader, $reporter, $report] = $this->context();
        $lecturer = Domain::user(UserRole::LecturerCoordinator);
        Domain::lecturer($lecturer, $class);
        $outsider = Domain::user(UserRole::LecturerCoordinator);
        Domain::lecturer($outsider, Domain::classRoom());

        $this->actingAs($lecturer, 'sanctum')->postJson('/api/v1/reports/'.$report->public_ref.'/assignments', [
            'assignee_id' => $outsider->id, 'reason' => 'Mencoba penugasan lintas kelas.',
        ])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertSame($leader->id, $report->fresh()->current_handler_id);
        $this->assertDatabaseMissing('report_assignments', ['report_id' => $report->id, 'assignee_id' => $outsider->id]);
    }

    public function test_reported_leader_is_never_automatically_assigned(): void
    {
        [$class, $leader, $reporter] = $this->context();

        $response = $this->actingAs($reporter, 'sanctum')->postJson('/api/v1/reports', [
            'category_id' => Domain::category('facilities')->id,
            'title' => 'Ketua kelas terlibat persoalan fasilitas',
            'description' => 'Ketua kelas adalah pihak yang terlibat langsung dalam persoalan fasilitas ini.',
            'involved_parties' => [$leader->name],
        ])->assertCreated()->assertJsonPath('data.status', 'needs_review');

        $report = Report::query()->where('public_ref', $response->json('data.public_ref'))->firstOrFail();
        $this->assertNull($report->current_handler_id);
        $this->assertSame(0, $report->assignments()->count());
    }

    public function test_reply_cannot_reference_another_report_update(): void
    {
        [$class, $leader, $reporter, $report] = $this->context();
        [, , $otherReporter, $otherReport] = $this->context();
        $foreign = $otherReport->updates()->create([
            'author_id' => $otherReporter->id, 'type' => 'update', 'body' => 'Isi laporan lain.',
        ]);

        $this->actingAs($reporter, 'sanctum')->postJson('/api/v1/reports/'.$report->public_ref.'/updates', [
            'body' => 'Mencoba membalas laporan lain.', 'reply_to_id' => $foreign->id,
        ])->assertUnprocessable();

        $this->assertSame(0, $report->updates()->count());
    }

    public function test_clarification_response_is_linked_once_to_its_request(): void
    {
        [$class, $leader, $reporter, $report] = $this->context();
        $request = $this->actingAs($leader, 'sanctum')
            ->postJson('/api/v1/reports/'.$report->public_ref.'/clarification-requests', ['body' => 'Mohon detail lokasi fasilitas.'])
            ->assertCreated();

        $this->actingAs($reporter, 'sanctum')->postJson('/api/v1/reports/'.$report->public_ref.'/updates', [
            'body' => 'Fasilitas berada di ruang kelas lantai dua.', 'type' => 'clarification_response',
        ])->assertCreated();
        $this->postJson('/api/v1/reports/'.$report->public_ref.'/updates', [
            'body' => 'Jawaban duplikat.', 'type' => 'clarification_response',
        ])->assertUnprocessable();

        $this->assertDatabaseHas('report_updates', [
            'report_id' => $report->id, 'type' => 'clarification_response', 'reply_to_id' => $request->json('data.id'),
        ]);
    }

    public function test_internal_note_is_hidden_from_reporter_even_if_reporter_is_class_leader(): void
    {
        [$class, $leader] = $this->context();
        $response = $this->actingAs($leader, 'sanctum')->postJson('/api/v1/reports', [
            'category_id' => Domain::category('facilities')->id,
            'title' => 'Pengaduan milik ketua kelas sendiri',
            'description' => 'Pengaduan ini dibuat ketua kelas dan harus ditangani pihak lain yang berwenang.',
        ])->assertCreated();
        $report = Report::query()->where('public_ref', $response->json('data.public_ref'))->firstOrFail();
        $lecturer = $this->assignLecturer($report);

        $this->actingAs($lecturer, 'sanctum')->postJson('/api/v1/reports/'.$report->public_ref.'/updates', [
            'body' => 'CATATAN-INTERNAL-RAHASIA', 'internal' => true,
        ])->assertCreated();

        $this->actingAs($leader, 'sanctum')->getJson('/api/v1/reports/'.$report->public_ref)
            ->assertOk()->assertJsonCount(0, 'data.updates');
        $this->postJson('/api/v1/reports/'.$report->public_ref.'/updates', [
            'body' => 'Catatan internal sendiri.', 'internal' => true,
        ])->assertUnprocessable();
    }

    public function test_legal_transition_rolls_back_when_audit_write_fails(): void
    {
        [$class, $leader, $reporter, $report] = $this->context();
        $histories = $report->statusHistories()->count();
        $notifications = AppNotification::query()->count();
        Event::listen('eloquent.creating: '.AuditLog::class, fn () => throw new RuntimeException('Audit unavailable.'));

        try {
            $this->actingAs($leader, 'sanctum')->patchJson('/api/v1/reports/'.$report->public_ref.'/status', [
                'status' => 'in_progress',
            ])->assertInternalServerError()->assertJsonPath('error.code', 'SERVER_ERROR');
        } finally {
            Event::forget('eloquent.creating: '.AuditLog::class);
        }

        $this->assertSame(ReportStatus::UnderReview, $report->fresh()->status);
        $this->assertSame($histories, $report->statusHistories()->count());
        $this->assertSame($notifications, AppNotification::query()->count());
    }

    public function test_forward_rolls_back_assignment_status_history_and_notifications_on_failure(): void
    {
        [$class, $leader, $reporter, $report] = $this->context();
        $lecturer = Domain::user(UserRole::LecturerCoordinator);
        Domain::lecturer($lecturer, $class);
        $histories = $report->statusHistories()->count();
        $notifications = AppNotification::query()->count();
        Event::listen('eloquent.creating: '.AuditLog::class, function (AuditLog $log): void {
            if ($log->action === AuditAction::ReportForwarded) {
                throw new RuntimeException('Audit unavailable.');
            }
        });

        try {
            $this->actingAs($lecturer, 'sanctum')->postJson('/api/v1/reports/'.$report->public_ref.'/forward', [
                'assignee_id' => $lecturer->id, 'reason' => 'Pengalihan penanganan ke dosen.',
            ])->assertInternalServerError();
        } finally {
            Event::forget('eloquent.creating: '.AuditLog::class);
        }

        $this->assertSame($leader->id, $report->fresh()->current_handler_id);
        $this->assertSame(ReportStatus::UnderReview, $report->fresh()->status);
        $this->assertSame(1, $report->activeAssignments()->count());
        $this->assertSame($histories, $report->statusHistories()->count());
        $this->assertSame($notifications, AppNotification::query()->count());
    }

    public function test_forward_records_actual_prior_status(): void
    {
        [$class, $leader, $reporter, $report] = $this->context();
        $lecturer = Domain::user(UserRole::LecturerCoordinator);
        Domain::lecturer($lecturer, $class);

        $this->actingAs($lecturer, 'sanctum')->postJson('/api/v1/reports/'.$report->public_ref.'/forward', [
            'assignee_id' => $lecturer->id, 'reason' => 'Pengalihan penanganan ke dosen.',
        ])->assertOk();

        $this->assertDatabaseHas('report_status_histories', [
            'report_id' => $report->id, 'from_status' => 'under_review', 'to_status' => 'forwarded',
        ]);
        $this->assertSame($lecturer->id, $report->fresh()->current_handler_id);
    }

    public function test_forward_status_cannot_bypass_assignment_endpoint(): void
    {
        [$class, $leader, $reporter, $report] = $this->context();

        $this->actingAs($leader, 'sanctum')->patchJson('/api/v1/reports/'.$report->public_ref.'/status', [
            'status' => 'forwarded', 'reason' => 'Pengalihan tanpa penangan tujuan.',
        ])->assertUnprocessable();

        $this->assertSame(ReportStatus::UnderReview, $report->fresh()->status);
        $this->assertSame($leader->id, $report->fresh()->current_handler_id);
    }

    public function test_revoked_class_leader_loses_report_privileges_even_with_stale_assignment(): void
    {
        [$class, $leader, $reporter, $report] = $this->context();
        $leader->classAdministratorAssignments()->where('class_id', $class->id)->update(['ends_at' => now()]);

        $this->actingAs($leader, 'sanctum')->getJson('/api/v1/reports/'.$report->public_ref)->assertForbidden();
        $this->patchJson('/api/v1/reports/'.$report->public_ref.'/status', ['status' => 'in_progress'])->assertForbidden();

        $this->assertSame(ReportStatus::UnderReview, $report->fresh()->status);
    }

    public function test_report_creation_cleans_uploaded_files_if_transaction_fails(): void
    {
        Storage::fake('private');
        [$class, $leader, $reporter] = $this->context();
        $count = Report::query()->count();
        Event::listen('eloquent.creating: '.AuditLog::class, fn () => throw new RuntimeException('Audit unavailable.'));

        try {
            $this->actingAs($reporter, 'sanctum')->postJson('/api/v1/reports', [
                'category_id' => Domain::category('facilities')->id,
                'title' => 'Pengaduan dengan lampiran yang gagal',
                'description' => 'Deskripsi pengaduan dengan lampiran yang harus dibatalkan bila audit gagal.',
                'attachments' => [UploadedFile::fake()->image('bukti.jpg')],
            ])->assertInternalServerError();
        } finally {
            Event::forget('eloquent.creating: '.AuditLog::class);
        }

        $this->assertSame($count, Report::query()->count());
        $this->assertDatabaseCount('attachments', 0);
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_appeal_requires_assigned_different_reviewer_and_cannot_bypass_status_endpoint(): void
    {
        [$class, $leader, $reporter, $report] = $this->context();
        app(ReportWorkflowService::class)->transition($report, ReportStatus::Rejected, $leader, ['reason' => 'Bukti belum cukup.']);
        $lecturer = Domain::user(UserRole::LecturerCoordinator);
        Domain::lecturer($lecturer, $class);
        $response = $this->actingAs($reporter, 'sanctum')->postJson('/api/v1/reports/'.$report->public_ref.'/appeals', [
            'reason' => 'Ada bukti tambahan yang memerlukan peninjauan.',
        ])->assertCreated();
        $id = $response->json('data.id');

        $this->actingAs($lecturer, 'sanctum')->getJson('/api/v1/appeals/'.$id)->assertForbidden();
        $this->postJson('/api/v1/appeals/'.$id.'/decision', [
            'status' => 'accepted', 'decision_note' => 'Keputusan tanpa penugasan.',
        ])->assertUnprocessable();
        $this->postJson('/api/v1/appeals/'.$id.'/reviewer', ['reviewer_id' => $lecturer->id])->assertOk();
        $this->getJson('/api/v1/reports/'.$report->public_ref)->assertOk()
            ->assertJsonPath('data.private_details.description', $report->privateDetails->description);
        $this->getJson('/api/v1/appeals/'.$id)->assertOk();
        $this->actingAs($leader, 'sanctum')->patchJson('/api/v1/reports/'.$report->public_ref.'/status', [
            'status' => 'resolved', 'resolution_summary' => 'Mencoba melewati banding.',
        ])->assertUnprocessable();
        $this->actingAs($lecturer, 'sanctum')->postJson('/api/v1/appeals/'.$id.'/decision', [
            'status' => 'accepted', 'decision_note' => str_repeat('Hasil peninjauan valid. ', 100),
        ])->assertOk();

        $this->assertSame(ReportStatus::Reopened, $report->fresh()->status);
        $this->assertSame(0, $report->activeAssignments()->where('assignment_type', AssignmentType::AppealReviewer->value)->count());
    }

    public function test_report_cannot_use_another_academic_term(): void
    {
        [$class, $leader, $reporter] = $this->context();
        $term = AcademicTerm::create([
            'code' => '2027-1', 'name' => 'Periode lain',
            'starts_on' => '2027-09-01', 'ends_on' => '2028-01-31', 'is_active' => false,
        ]);

        $this->actingAs($reporter, 'sanctum')->postJson('/api/v1/reports', [
            'category_id' => Domain::category('facilities')->id,
            'title' => 'Percobaan periode akademik kelas lain',
            'description' => 'Uraian panjang dengan periode akademik yang tidak sesuai kelas pelapor.',
            'academic_term_id' => $term->id,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('reports', 1);
    }

    public function test_valid_long_resolution_is_saved_with_bounded_timeline(): void
    {
        [$class, $leader, $reporter, $report] = $this->context();
        $workflow = app(ReportWorkflowService::class);
        $workflow->transition($report, ReportStatus::ResolutionProposed, $leader);
        $summary = str_repeat('Hasil penanganan. ', 200);

        $this->actingAs($leader, 'sanctum')->patchJson('/api/v1/reports/'.$report->public_ref.'/status', [
            'status' => 'resolved', 'resolution_summary' => $summary,
        ])->assertOk();

        $this->assertSame(trim($summary), $report->fresh()->resolution_summary);
        $this->assertSame(1000, mb_strlen($report->statusHistories()->where('to_status', 'resolved')->firstOrFail()->reason));
    }

    public function test_real_private_disk_upload_is_readable_only_via_authorized_download(): void
    {
        [$class, $leader, $reporter, $report] = $this->context();
        $path = null;

        try {
            $this->actingAs($reporter, 'sanctum')->postJson('/api/v1/reports', [
                'category_id' => Domain::category('facilities')->id,
                'title' => 'Pengaduan dengan bukti tersimpan privat',
                'description' => 'Bukti ini diunggah pada disk privat asli tanpa Storage fake untuk verifikasi konfigurasi.',
                'attachments' => [UploadedFile::fake()->image('bukti-private.jpg')],
            ])->assertCreated();
            $attachment = Attachment::query()->firstOrFail();
            $path = $attachment->path;
            $this->assertTrue(Storage::disk('private')->exists($path));
            $this->get('/api/v1/attachments/'.$attachment->id)->assertOk()
                ->assertHeader('X-Content-Type-Options', 'nosniff');
            $outsider = Domain::user(UserRole::Student);
            $this->actingAs($outsider, 'sanctum')->getJson('/api/v1/attachments/'.$attachment->id)->assertForbidden();
            $this->get('/storage/'.$path)->assertNotFound();
        } finally {
            if ($path !== null) {
                Storage::disk('private')->delete($path);
            }
        }
    }
}
