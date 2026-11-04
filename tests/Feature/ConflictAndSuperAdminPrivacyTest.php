<?php

namespace Tests\Feature;

use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Models\Report;
use App\Services\ReportWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\Domain;
use Tests\TestCase;

/**
 * Skenario wajib #4 dan #5.
 */
class ConflictAndSuperAdminPrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Domain::seedCatalog();
    }

    /** #4 Dosen yang menjadi pihak terlapor tidak bisa menangani kasusnya sendiri. */
    public function test_lecturer_who_is_reported_cannot_handle_own_case(): void
    {
        $class = Domain::classRoom();
        $lecturer = Domain::user(UserRole::LecturerCoordinator, ['name' => 'Dr. Budi Santoso']);
        Domain::lecturer($lecturer, $class);

        $reporter = Domain::user(UserRole::Student, ['nim' => 'R1']);
        Domain::member($reporter, $class);

        $this->actingAs($reporter, 'sanctum');
        $response = $this->postJson('/api/v1/reports', [
            'category_id' => Domain::category('lecturer_conduct')->id,
            'title' => 'Dosen tidak hadir tanpa kabar',
            'description' => 'Dosen pengampu tidak hadir selama tiga pertemuan berturut-turut tanpa informasi.',
            'involved_parties' => ['Dr. Budi Santoso'],
        ])->assertCreated();

        $report = Report::query()->where('public_ref', $response->json('data.public_ref'))->firstOrFail();

        // Laporan sensitif menunggu peninjauan manusia.
        $this->assertSame(ReportStatus::NeedsReview, $report->status);

        // Dosen tersebut adalah pihak yang dilaporkan: policy dan service menolak.
        $this->assertFalse($lecturer->can('updateStatus', $report));

        $this->expectException(ValidationException::class);
        app(ReportWorkflowService::class)->transition(
            $report,
            ReportStatus::UnderReview,
            $lecturer,
            ['reason' => 'Saya coba tangani sendiri.'],
        );
    }

    public function test_conflict_recorded_blocks_assignment_and_status_change(): void
    {
        $class = Domain::classRoom();
        $lecturer = Domain::user(UserRole::LecturerCoordinator);
        $other = Domain::user(UserRole::LecturerCoordinator);
        Domain::lecturer($lecturer, $class);
        Domain::lecturer($other, $class);

        $reporter = Domain::user(UserRole::Student, ['nim' => 'R2']);
        Domain::member($reporter, $class);

        $this->actingAs($reporter, 'sanctum');
        $response = $this->postJson('/api/v1/reports', [
            'category_id' => Domain::category('academic')->id,
            'title' => 'Beban tugas tidak seimbang',
            'description' => 'Pembagian tugas kelompok tidak seimbang dan tidak pernah dijelaskan di awal.',
        ])->assertCreated();

        $report = Report::query()->where('public_ref', $response->json('data.public_ref'))->firstOrFail();

        // Ketua kelas otomatis menjadi penangan awal kategori rutin;
        // buat konflik untuk dosen lain sebagai uji.
        $report->conflictsOfInterest()->create([
            'user_id' => $other->id,
            'status' => 'declared',
            'relationship' => 'rekan',
        ]);

        $this->assertFalse($other->can('updateStatus', $report));
        $this->assertFalse($other->can('assign', $report));

        $this->actingAs($other, 'sanctum')
            ->patchJson('/api/v1/reports/'.$report->public_ref.'/status', [
                'status' => 'in_progress',
            ])
            ->assertForbidden();
    }

    /** #5 Super Admin tidak otomatis memperoleh akses isi pengaduan. */
    public function test_super_admin_has_no_automatic_access_to_report_contents(): void
    {
        $class = Domain::classRoom();
        $leader = Domain::user(UserRole::ClassLeader, ['nim' => 'L1']);
        Domain::leader($leader, $class);

        $reporter = Domain::user(UserRole::Student, ['nim' => 'R3']);
        Domain::member($reporter, $class);

        $this->actingAs($reporter, 'sanctum');
        $response = $this->postJson('/api/v1/reports', [
            'category_id' => Domain::category('facilities')->id,
            'title' => 'AC kelas rusak sejak awal semester',
            'description' => 'Pendingin ruangan kelas tidak berfungsi dan sudah dilaporkan berkali-kali tanpa hasil.',
        ])->assertCreated();

        $report = Report::query()->where('public_ref', $response->json('data.public_ref'))->firstOrFail();

        $admin = Domain::user(UserRole::SuperAdmin);

        // Super Admin tidak memiliki permission membaca isi privat...
        $this->assertFalse($admin->hasPermission('reports.view.private'));
        $this->assertFalse($admin->can('view', $report));
        $this->assertFalse($admin->can('viewPrivateDetails', $report));

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/'.$report->public_ref)
            ->assertForbidden();

        // ...dan daftar laporan yang dikembalikan kosong.
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Dashboard Super Admin hanya berisi data sistem.
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.role', 'super_admin')
            ->assertJsonMissingPath('data.reports');
    }

    public function test_super_admin_cannot_use_status_endpoint_even_if_assigned_permission_missing(): void
    {
        $class = Domain::classRoom();
        $leader = Domain::user(UserRole::ClassLeader, ['nim' => 'L2']);
        Domain::leader($leader, $class);
        $reporter = Domain::user(UserRole::Student, ['nim' => 'R4']);
        Domain::member($reporter, $class);

        $this->actingAs($reporter, 'sanctum');
        $response = $this->postJson('/api/v1/reports', [
            'category_id' => Domain::category('grouping')->id,
            'title' => 'Kelompok tidak pernah diundi terbuka',
            'description' => 'Pembagian kelompok dilakukan tertutup dan tidak sesuai kesepakatan kelas.',
        ])->assertCreated();

        $report = Report::query()->where('public_ref', $response->json('data.public_ref'))->firstOrFail();

        $admin = Domain::user(UserRole::SuperAdmin);

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/reports/'.$report->public_ref.'/status', [
                'status' => 'resolved',
                'resolution_summary' => 'Saya selesaikan langsung sebagai admin.',
            ])
            ->assertForbidden();
    }
}
