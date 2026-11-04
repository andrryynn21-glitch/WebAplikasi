<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AppNotification;
use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Domain;
use Tests\TestCase;

class IntegratedHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Domain::seedCatalog();
    }

    public function test_real_routing_notification_is_class_scoped_and_hidden_after_assignment_revocation(): void
    {
        $class = Domain::classRoom();
        $student = Domain::user(UserRole::Student);
        Domain::member($student, $class);
        $lecturer = Domain::user(UserRole::LecturerCoordinator);
        Domain::lecturer($lecturer, $class);

        $this->actingAs($student, 'sanctum')->postJson('/api/v1/reports', [
            'category_id' => Domain::category('lecturer_conduct')->id,
            'title' => 'Laporan fiktif untuk regression notifikasi kelas',
            'description' => 'Isi fiktif cukup panjang untuk membuktikan scope notifikasi tanpa data mahasiswa nyata.',
        ])->assertCreated();

        $notification = AppNotification::query()->where('user_id', $lecturer->id)->firstOrFail();
        $this->assertSame($class->id, $notification->data['class_id']);
        $this->assertArrayNotHasKey('description', $notification->data);
        $this->actingAs($lecturer, 'sanctum')->getJson('/api/v1/notifications')->assertJsonCount(1, 'data');

        $class->lecturerAssignments()->where('lecturer_id', $lecturer->id)->update(['ends_at' => now()]);
        $this->getJson('/api/v1/notifications')->assertJsonCount(0, 'data');
    }

    public function test_numeric_report_identifier_is_not_a_supported_private_resource_reference(): void
    {
        $class = Domain::classRoom();
        $student = Domain::user(UserRole::Student);
        Domain::member($student, $class);
        $response = $this->actingAs($student, 'sanctum')->postJson('/api/v1/reports', [
            'category_id' => Domain::category('facilities')->id,
            'title' => 'Pengaduan fiktif dengan referensi publik',
            'description' => 'Deskripsi fiktif untuk memastikan hanya public_ref yang menjadi kontrak route laporan.',
        ])->assertCreated();
        $report = Report::query()->where('public_ref', $response->json('data.public_ref'))->firstOrFail();

        $this->assertSame('/api/v1/reports/'.$report->public_ref, route('reports.show', ['report' => $report], false));
        $this->getJson('/api/v1/reports/'.$report->id)->assertNotFound();
        $this->getJson('/api/v1/reports/'.$report->public_ref)
            ->assertOk()
            ->assertJsonPath('data.public_ref', $report->public_ref);
    }
}
