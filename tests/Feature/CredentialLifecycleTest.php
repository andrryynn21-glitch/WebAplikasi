<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Permission;
use App\Models\User;
use App\Services\RoleAssignmentService;
use App\Services\UserAdministrationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\Support\Domain;
use Tests\TestCase;

class CredentialLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Domain::seedCatalog();
    }

    public function test_password_change_requires_current_password_and_confirmation(): void
    {
        $user = Domain::user(UserRole::SuperAdmin);
        $token = $user->createToken('current')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/auth/password/change', [
            'current_password' => 'WrongPassword123!',
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
        ])->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['current_password']]]);

        $this->withToken($token)->postJson('/api/v1/auth/password/change', [
            'current_password' => 'Password123!',
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'DifferentSecure123!',
        ])->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['password']]]);

        $this->assertTrue(Hash::check('Password123!', $user->fresh()->password));
    }

    public function test_password_change_keeps_current_token_and_revokes_other_tokens(): void
    {
        $user = Domain::user(UserRole::SuperAdmin);
        $current = $user->createToken('current');
        $other = $user->createToken('other');

        $this->withToken($current->plainTextToken)->postJson('/api/v1/auth/password/change', [
            'current_password' => 'Password123!',
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
        ])->assertOk();

        $this->assertTrue(Hash::check('NewSecurePassword123!', $user->fresh()->password));
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $current->accessToken->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $other->accessToken->id]);
        $this->withToken($current->plainTextToken)->getJson('/api/v1/auth/me')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['subject_id' => $user->id, 'action' => 'password_changed']);
        $this->assertDatabaseHas('notifications', ['user_id' => $user->id, 'type' => 'security_notice']);
    }

    public function test_forgot_response_does_not_reveal_account_and_log_mailer_never_creates_token(): void
    {
        $user = Domain::user(UserRole::SuperAdmin);
        config()->set('mail.default', 'log');

        $existing = $this->postJson('/api/v1/auth/password/forgot', ['email' => $user->email])->assertOk();
        $missing = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'missing@example.test'])->assertOk();

        $this->assertSame($existing->json('message'), $missing->json('message'));
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'password_reset_requested']);
    }

    public function test_valid_reset_is_single_use_revokes_tokens_and_contains_no_secrets_in_audit(): void
    {
        Notification::fake();
        config()->set('mail.default', 'array');
        $user = Domain::user(UserRole::SuperAdmin);
        $user->createToken('old-session');
        $token = null;

        $this->postJson('/api/v1/auth/password/forgot', ['email' => $user->email])->assertOk();
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token) {
            $token = $notification->token;

            return is_string($token) && $token !== '';
        });

        $payload = [
            'email' => $user->email,
            'token' => $token,
            'password' => 'RecoveredPassword123!',
            'password_confirmation' => 'RecoveredPassword123!',
        ];
        $this->postJson('/api/v1/auth/password/reset', $payload)->assertOk();
        $this->assertTrue(Hash::check('RecoveredPassword123!', $user->fresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->postJson('/api/v1/auth/password/reset', $payload)
            ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['token']]]);

        $serializedAudit = DB::table('audit_logs')->whereIn('action', [
            'password_reset_requested', 'password_reset_completed',
        ])->get()->toJson();
        $this->assertStringNotContainsString($token, $serializedAudit);
        $this->assertStringNotContainsString('RecoveredPassword123!', $serializedAudit);
    }

    public function test_invalid_and_expired_reset_tokens_are_rejected(): void
    {
        $user = Domain::user(UserRole::SuperAdmin);
        $payload = [
            'email' => $user->email,
            'password' => 'RecoveredPassword123!',
            'password_confirmation' => 'RecoveredPassword123!',
        ];

        $this->postJson('/api/v1/auth/password/reset', $payload + ['token' => 'invalid-token'])
            ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['token']]]);

        $token = Password::broker()->createToken($user);
        DB::table('password_reset_tokens')->where('email', $user->email)
            ->update(['created_at' => now()->subMinutes(61)]);
        $this->postJson('/api/v1/auth/password/reset', $payload + ['token' => $token])
            ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['token']]]);
        $this->assertTrue(Hash::check('Password123!', $user->fresh()->password));
    }

    public function test_credential_endpoint_cannot_change_another_user(): void
    {
        $actor = Domain::user(UserRole::SuperAdmin);
        $target = Domain::user(UserRole::Student);
        $token = $actor->createToken('current')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/auth/password/change', [
            'user_id' => $target->id,
            'current_password' => 'Password123!',
            'password' => 'ActorPasswordChanged123!',
            'password_confirmation' => 'ActorPasswordChanged123!',
        ])->assertOk();

        $this->assertTrue(Hash::check('ActorPasswordChanged123!', $actor->fresh()->password));
        $this->assertTrue(Hash::check('Password123!', $target->fresh()->password));
    }

    public function test_last_active_super_admin_cannot_be_demoted_or_suspended(): void
    {
        $admin = Domain::user(UserRole::SuperAdmin);
        $actor = Domain::user(UserRole::Student);
        $actor->role->permissions()->syncWithoutDetaching(
            Permission::query()->whereIn('key', ['roles.manage', 'users.manage'])->pluck('id')
        );

        $this->actingAs($actor, 'sanctum')->postJson('/api/v1/admin/users/'.$admin->id.'/role', [
            'role' => UserRole::Student->value, 'reason' => 'Mencoba menurunkan administrator aktif terakhir.',
        ])->assertUnprocessable();
        $this->postJson('/api/v1/admin/users/'.$admin->id.'/suspend', [
            'reason' => 'Mencoba menonaktifkan administrator aktif terakhir.',
        ])->assertUnprocessable();

        $other = Domain::user(UserRole::Student);
        $this->actingAs($admin->fresh(), 'sanctum')->postJson('/api/v1/admin/users/'.$other->id.'/role', [
            'role' => UserRole::SuperAdmin->value, 'reason' => 'Menambahkan administrator kedua yang sah.',
        ])->assertOk();
        $this->postJson('/api/v1/admin/users/'.$other->id.'/suspend', [
            'reason' => 'Menonaktifkan salah satu dari dua administrator aktif.',
        ])->assertOk();
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_stale_concurrent_admin_requests_cannot_remove_every_active_admin(): void
    {
        $first = Domain::user(UserRole::SuperAdmin);
        $second = Domain::user(UserRole::SuperAdmin);
        $roles = app(RoleAssignmentService::class);

        $roles->assign($second, UserRole::Student, $first, 'Permintaan pertama menurunkan administrator kedua.');

        try {
            $roles->assign($first, UserRole::Student, $second, 'Permintaan stale mencoba menurunkan administrator tersisa.');
            $this->fail('Permintaan stale seharusnya ditolak.');
        } catch (AuthorizationException) {
            $this->assertTrue($first->fresh()->hasRole(UserRole::SuperAdmin));
        }

        $this->assertSame(1, User::query()->whereHas('role', fn ($q) => $q->where('key', 'super_admin'))
            ->where('is_active', true)->count());
    }

    public function test_stale_concurrent_suspend_and_user_deletion_cannot_remove_last_admin(): void
    {
        $first = Domain::user(UserRole::SuperAdmin);
        $second = Domain::user(UserRole::SuperAdmin);
        $service = app(UserAdministrationService::class);

        $service->suspend($second, $first, 'Permintaan pertama menonaktifkan administrator kedua.');

        try {
            $service->suspend($first, $second, 'Permintaan stale mencoba menonaktifkan administrator tersisa.');
            $this->fail('Permintaan stale seharusnya ditolak.');
        } catch (AuthorizationException) {
            $this->assertTrue($first->fresh()->is_active);
        }

        $this->actingAs($first, 'sanctum')->deleteJson('/api/v1/admin/users/'.$first->id)
            ->assertNotFound();
        $this->assertSame(1, User::query()->whereHas('role', fn ($q) => $q->where('key', 'super_admin'))
            ->where('is_active', true)->count());
    }
}
