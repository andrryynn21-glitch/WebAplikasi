<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\Domain;
use Tests\TestCase;

class BootstrapAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_admin_is_created_with_hashed_password_without_private_permissions(): void
    {
        Domain::seedCatalog();

        $this->artisan('app:bootstrap-admin', ['--name' => 'Administrator', '--email' => 'admin@example.test'])
            ->expectsQuestion('Password administrator', 'BootstrapPassword123!')
            ->expectsQuestion('Konfirmasi password', 'BootstrapPassword123!')
            ->expectsOutput('Super Admin pertama berhasil dibuat. Akun ini tidak memiliki akses isi pengaduan.')
            ->assertExitCode(0);

        $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();
        $this->assertTrue($admin->hasRole(UserRole::SuperAdmin));
        $this->assertTrue(Hash::check('BootstrapPassword123!', $admin->password));
        $this->assertFalse($admin->hasPermission('reports.view.private'));
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $admin->id, 'action' => 'role_assigned']);
    }

    public function test_bootstrap_does_not_create_another_admin_or_modify_existing_account(): void
    {
        Domain::seedCatalog();
        Domain::user(UserRole::SuperAdmin);

        $this->artisan('app:bootstrap-admin', ['--name' => 'Second', '--email' => 'second@example.test'])
            ->expectsOutput('Super Admin sudah tersedia. Gunakan proses administrasi untuk akun tambahan.')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['email' => 'second@example.test']);
    }

    public function test_academic_term_catalog_requires_authentication_and_preserves_dates(): void
    {
        $term = Domain::term();

        $this->getJson('/api/v1/academic-terms')->assertUnauthorized();
        $user = Domain::user(UserRole::Student);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/academic-terms')->assertOk()
            ->assertJsonPath('data.0.code', '2026-1')
            ->assertJsonPath('data.0.starts_on', $term->starts_on->toDateString());
    }

    public function test_real_bearer_token_login_current_user_and_logout(): void
    {
        Domain::seedCatalog();
        $user = Domain::user(UserRole::Student, ['email' => 'student@example.test']);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'Password123!', 'device_name' => 'integration',
        ])->assertOk()->assertJsonPath('data.token_type', 'Bearer');
        $token = $login->json('data.token');
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.email', 'student@example.test');
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertSame(0, $user->tokens()->count());
        auth()->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_authenticated_api_rate_limit_is_enforced(): void
    {
        Domain::seedCatalog();
        $user = Domain::user(UserRole::Student);
        $this->actingAs($user, 'sanctum');

        for ($i = 0; $i < 120; $i++) {
            $this->getJson('/api/v1/academic-terms')->assertOk();
        }

        $this->getJson('/api/v1/academic-terms')->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMITED')->assertHeader('Retry-After');
    }

    public function test_api_without_accept_header_returns_json_401_instead_of_missing_login_redirect(): void
    {
        $this->get('/api/v1/auth/me')->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_invalid_lecturer_assignment_returns_validation_error_without_creating_assignment(): void
    {
        Domain::seedCatalog();
        $class = Domain::classRoom();
        $admin = Domain::user(UserRole::SuperAdmin);
        $student = Domain::user(UserRole::Student);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/classes/'.$class->id.'/lecturers', [
            'lecturer_id' => $student->id,
        ])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertDatabaseCount('lecturer_assignments', 0);
    }

    public function test_nonstudent_class_leader_assignment_returns_validation_error(): void
    {
        Domain::seedCatalog();
        $class = Domain::classRoom();
        $admin = Domain::user(UserRole::SuperAdmin);
        $lecturer = Domain::user(UserRole::LecturerCoordinator);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/classes/'.$class->id.'/leader', [
            'user_id' => $lecturer->id,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('class_administrator_assignments', 0);
    }
}
