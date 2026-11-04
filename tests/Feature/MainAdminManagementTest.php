<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Domain;
use Tests\TestCase;

class MainAdminManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Domain::seedCatalog();
    }

    public function test_authorized_admin_can_assign_a_second_independent_super_admin(): void
    {
        $primary = Domain::user(UserRole::SuperAdmin);
        $second = Domain::user(UserRole::Student);

        $this->actingAs($primary, 'sanctum')->postJson('/api/v1/admin/users/'.$second->id.'/role', [
            'role' => UserRole::SuperAdmin->value,
            'reason' => 'Menambah administrator tepercaya untuk keberlanjutan operasional.',
        ])->assertOk();

        $this->assertTrue($primary->fresh()->hasRole(UserRole::SuperAdmin));
        $this->assertTrue($second->fresh()->hasRole(UserRole::SuperAdmin));
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $primary->id,
            'subject_id' => $second->id,
            'action' => 'role_assigned',
        ]);

        $this->actingAs($second->fresh(), 'sanctum')->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.role', UserRole::SuperAdmin->value)
            ->assertJsonMissingPath('data.reports');
    }

    public function test_admin_can_assign_lecturer_role_then_assign_that_lecturer_to_a_class(): void
    {
        $admin = Domain::user(UserRole::SuperAdmin);
        $lecturer = Domain::user(UserRole::Student);
        $class = Domain::classRoom();

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/users/'.$lecturer->id.'/role', [
            'role' => UserRole::LecturerCoordinator->value,
            'reason' => 'Menetapkan akun dosen koordinator untuk kelas yang dikelola.',
        ])->assertOk();

        $response = $this->postJson('/api/v1/admin/classes/'.$class->id.'/lecturers', [
            'lecturer_id' => $lecturer->id,
            'role' => 'coordinator',
        ])->assertCreated();

        $this->assertTrue($lecturer->fresh()->hasRole(UserRole::LecturerCoordinator));
        $this->assertDatabaseHas('lecturer_assignments', [
            'id' => $response->json('data.id'),
            'class_id' => $class->id,
            'lecturer_id' => $lecturer->id,
            'assigned_by' => $admin->id,
            'role' => 'coordinator',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'lecturer_assigned',
            'class_id' => $class->id,
        ]);
    }
}
