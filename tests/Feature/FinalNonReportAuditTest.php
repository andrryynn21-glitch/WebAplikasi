<?php

namespace Tests\Feature;

use App\Enums\AttachmentType;
use App\Enums\AuditAction;
use App\Enums\IdentityStatus;
use App\Enums\UserRole;
use App\Models\Aspiration;
use App\Models\AspirationComment;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\IdentityVerification;
use App\Models\User;
use App\Services\AspirationService;
use App\Services\AuditLogService;
use App\Services\IdentityVerificationService;
use App\Services\PrivateStorageService;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\Support\Domain;
use Tests\TestCase;

class FinalNonReportAuditTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Domain::seedCatalog();
    }

    #[TestWith(['verified'])]
    #[TestWith(['pending_verification'])]
    public function test_profile_cannot_replace_a_name_under_identity_review(string $status): void
    {
        $student = Domain::user(UserRole::Student, ['name' => 'Reviewed Student', 'identity_status' => $status]);

        $this->actingAs($student, 'sanctum')->patchJson('/api/v1/profile', ['name' => 'Different Identity'])
            ->assertUnprocessable()->assertJsonValidationErrors('name', 'error.details');

        $this->assertSame('Reviewed Student', $student->fresh()->name);
        $this->assertSame($status, $student->fresh()->identity_status->value);
    }

    public function test_profile_still_accepts_phone_and_unchanged_verified_name(): void
    {
        $student = Domain::user(UserRole::Student, ['name' => 'Reviewed Student', 'nim' => 'REVIEWED-1']);

        $this->actingAs($student, 'sanctum')->patchJson('/api/v1/profile', [
            'name' => $student->name, 'phone' => '0800000000', 'nim' => 'REPLACEMENT',
        ])->assertOk()->assertJsonPath('data.phone', '0800000000')->assertJsonPath('data.nim', 'REVIEWED-1');

        $this->assertSame(IdentityStatus::Verified, $student->fresh()->identity_status);
    }

    #[TestWith(['class_leader'])]
    #[TestWith(['lecturer_coordinator'])]
    public function test_handler_name_cannot_change_even_without_verified_identity(string $role): void
    {
        $handler = Domain::user(UserRole::from($role), ['name' => 'Named Handler', 'identity_status' => IdentityStatus::Unverified]);

        $this->actingAs($handler, 'sanctum')->patchJson('/api/v1/profile', ['name' => 'Unrecognizable Alias'])
            ->assertUnprocessable()->assertJsonValidationErrors('name', 'error.details');

        $this->assertSame('Named Handler', $handler->fresh()->name);
    }

    public function test_approval_binds_the_profile_to_the_reviewed_full_name_and_nim(): void
    {
        Storage::fake('private');
        $student = Domain::user(UserRole::Student, ['name' => 'Unreviewed Alias', 'identity_status' => IdentityStatus::Unverified]);
        $reviewer = Domain::user(UserRole::IdentityVerifier);
        $service = app(IdentityVerificationService::class);
        $verification = $service->submit($student, UploadedFile::fake()->image('identity.jpg'), 'REVIEWED-1', 'Reviewed Full Name');

        $service->approve($verification, $reviewer);

        $this->assertSame('Reviewed Full Name', $student->fresh()->name);
        $this->assertSame('REVIEWED-1', $student->fresh()->nim);
        $this->assertSame(IdentityStatus::Verified, $student->fresh()->identity_status);
    }

    public function test_identity_review_reason_is_not_copied_into_admin_audit(): void
    {
        Storage::fake('private');
        $student = Domain::user(UserRole::Student, ['identity_status' => IdentityStatus::Unverified]);
        $reviewer = Domain::user(UserRole::IdentityVerifier);
        $admin = Domain::user(UserRole::SuperAdmin);
        $service = app(IdentityVerificationService::class);
        $verification = $service->submit($student, UploadedFile::fake()->image('identity.jpg'), 'REVIEWED-1', 'Reviewed Full Name');
        $service->reject($verification, $reviewer, 'PRIVATE-IDENTITY-CANARY must remain in the identity review.');

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/audit-logs')
            ->assertOk()->assertDontSee('PRIVATE-IDENTITY-CANARY');

        $this->assertStringNotContainsString('PRIVATE-IDENTITY-CANARY', json_encode(AuditLog::firstOrFail()->changes));
        $this->assertStringContainsString('PRIVATE-IDENTITY-CANARY', $verification->fresh()->rejection_reason);
    }

    public function test_audit_metadata_drops_free_text_and_unlabelled_nested_values(): void
    {
        $log = app(AuditLogService::class)->record(AuditAction::ContentModerated, null, null, null, [
            'action' => 'hide', 'from' => 'published', 'to' => 'hidden',
            'reason' => 'PRIVATE-REASON-CANARY',
            'nested' => ['status' => 'hidden', 'note' => 'PRIVATE-NOTE-CANARY', ['PRIVATE-LIST-CANARY']],
            'extra' => 'PRIVATE-UNKNOWN-CANARY',
        ]);

        $this->assertStringNotContainsString('PRIVATE-', json_encode($log->changes));
        $this->assertSame('hide', $log->changes['action']);
        $this->assertSame('published', $log->changes['from']);
    }

    public function test_existing_audit_rows_are_scrubbed_on_read_without_rewriting_history(): void
    {
        $admin = Domain::user(UserRole::SuperAdmin);
        $log = AuditLog::create(['action' => AuditAction::IdentityRejected, 'changes' => [
            'user_id' => $admin->id, 'reason' => 'PRIVATE-HISTORICAL-CANARY',
        ]]);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/audit-logs')
            ->assertOk()->assertDontSee('PRIVATE-HISTORICAL-CANARY');

        $this->assertSame('PRIVATE-HISTORICAL-CANARY', $log->fresh()->changes['reason']);
    }

    #[TestWith(['title', '<i></i>'])]
    #[TestWith(['body', '<div> </div>'])]
    #[TestWith(['title', '<b>A</b>'])]
    public function test_aspiration_validates_the_text_that_will_be_stored(string $field, string $value): void
    {
        $student = Domain::user(UserRole::Student);
        Domain::member($student, Domain::classRoom());

        $this->actingAs($student, 'sanctum')->postJson('/api/v1/aspirations', array_replace([
            'title' => 'Valid suggestion', 'body' => 'A complete class suggestion.',
        ], [$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field, 'error.details');

        $this->assertDatabaseCount('aspirations', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_comment_rejects_tag_only_text_without_side_effects(): void
    {
        $student = Domain::user(UserRole::Student);
        $class = Domain::classRoom();
        Domain::member($student, $class);
        $aspiration = Aspiration::create(['class_id' => $class->id, 'author_id' => Domain::user(UserRole::Student)->id,
            'title' => 'Valid suggestion', 'body' => 'A complete class suggestion.']);

        $this->actingAs($student, 'sanctum')->postJson('/api/v1/aspirations/'.$aspiration->id.'/comments', ['body' => '<br>'])
            ->assertUnprocessable()->assertJsonValidationErrors('body', 'error.details');

        $this->assertDatabaseCount('aspiration_comments', 0);
        $this->assertSame(0, $aspiration->fresh()->comments_count);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_unsupport_rechecks_hidden_content_instead_of_trusting_stale_model(): void
    {
        $student = Domain::user(UserRole::Student);
        $class = Domain::classRoom();
        Domain::member($student, $class);
        $aspiration = Aspiration::create(['class_id' => $class->id, 'author_id' => Domain::user(UserRole::Student)->id,
            'title' => 'Valid suggestion', 'body' => 'A complete class suggestion.'])->refresh();
        $service = app(AspirationService::class);
        $service->support($aspiration, $student);
        Aspiration::whereKey($aspiration->id)->update(['status' => 'hidden']);

        $this->assertThrows(fn () => $service->unsupport($aspiration, $student), AuthorizationException::class);

        $this->assertDatabaseCount('aspiration_supports', 1);
        $this->assertSame(1, $aspiration->fresh()->supports_count);
    }

    public function test_reply_rechecks_parent_visibility_after_waiting_for_aspiration_lock(): void
    {
        $student = Domain::user(UserRole::Student);
        $class = Domain::classRoom();
        Domain::member($student, $class);
        $aspiration = Aspiration::create(['class_id' => $class->id, 'author_id' => $student->id,
            'title' => 'Valid suggestion', 'body' => 'A complete class suggestion.'])->refresh();
        $parent = $aspiration->comments()->create(['author_id' => $student->id, 'body' => 'Parent comment.']);
        $interleaved = false;
        DB::connection()->beforeExecuting(function (string $query) use ($parent, &$interleaved): void {
            if (! $interleaved && str_contains($query, '`aspirations`') && str_contains($query, 'for update')) {
                $interleaved = true;
                AspirationComment::whereKey($parent->id)->update(['status' => 'hidden']);
            }
        });

        $this->assertThrows(fn () => app(AspirationService::class)->comment($aspiration, $student, 'Reply to hidden parent.', $parent->id), ValidationException::class);

        $this->assertTrue($interleaved);
        $this->assertDatabaseCount('aspiration_comments', 1);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_future_class_leader_is_not_current_leader(): void
    {
        $class = Domain::classRoom();
        $leader = Domain::user(UserRole::ClassLeader);
        Domain::leader($leader, $class)->forceFill(['starts_at' => now()->addDay()])->save();

        $this->assertFalse((bool) $class->currentLeader());
        $this->assertFalse($leader->isClassLeaderOf($class->id));
    }

    public function test_demo_seeder_refuses_production_before_any_data_changes(): void
    {
        $this->app->instance('env', 'production');

        $this->assertThrows(fn () => Model::unguarded(fn () => (new DemoDataSeeder)->run()), RuntimeException::class);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('classes', 0);
        $this->assertDatabaseCount('academic_terms', 0);
    }

    public function test_demo_seeder_refuses_to_overwrite_existing_accounts_or_class_history(): void
    {
        $existing = Domain::user(UserRole::Student, ['email' => 'admin@example.test', 'name' => 'Existing Account']);
        $passwordHash = $existing->password;

        $this->assertThrows(fn () => Model::unguarded(fn () => (new DemoDataSeeder)->run()), RuntimeException::class);

        $this->assertSame($passwordHash, $existing->fresh()->password);
        $this->assertSame('Existing Account', $existing->fresh()->name);
        $this->assertSame(UserRole::Student, $existing->fresh()->roleKey());
        $this->assertSame(1, User::count());
        $this->assertDatabaseCount('classes', 0);
    }

    #[TestWith(['identity'])]
    #[TestWith(['attachment'])]
    public function test_private_upload_is_removed_if_metadata_persistence_fails(string $type): void
    {
        Storage::fake('private');
        $student = Domain::user(UserRole::Student, ['nim' => 'UPLOAD-1']);
        $class = Domain::classRoom();
        $aspiration = Aspiration::create(['class_id' => $class->id, 'author_id' => $student->id,
            'title' => 'Valid suggestion', 'body' => 'A complete class suggestion.']);
        $event = 'eloquent.creating: '.($type === 'identity' ? IdentityVerification::class : Attachment::class);
        Event::listen($event, fn () => throw new RuntimeException('Synthetic metadata persistence failure.'));

        try {
            $this->assertThrows(fn () => $type === 'identity'
                ? app(PrivateStorageService::class)->storeIdentityDocument($student, UploadedFile::fake()->image('identity.jpg'))
                : app(PrivateStorageService::class)->storeAttachment(UploadedFile::fake()->image('attachment.jpg'), $aspiration, $student, AttachmentType::AspirationImage), RuntimeException::class);
        } finally {
            Event::forget($event);
        }

        $this->assertSame([], Storage::disk('private')->allFiles());
        $this->assertDatabaseCount('identity_verifications', 0);
        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_identity_upload_is_removed_when_later_transaction_step_fails(): void
    {
        Storage::fake('private');
        $student = Domain::user(UserRole::Student, ['nim' => 'UPLOAD-1', 'identity_status' => IdentityStatus::Unverified]);
        $event = 'eloquent.saving: '.User::class;
        Event::listen($event, function (User $user): void {
            if ($user->identity_status === IdentityStatus::PendingVerification) {
                throw new RuntimeException('Synthetic user persistence failure.');
            }
        });

        try {
            $this->assertThrows(fn () => app(IdentityVerificationService::class)->submit($student,
                UploadedFile::fake()->image('identity.jpg'), 'UPLOAD-1', 'Reviewed Name'), RuntimeException::class);
        } finally {
            Event::forget($event);
        }

        $this->assertSame([], Storage::disk('private')->allFiles());
        $this->assertDatabaseCount('identity_verifications', 0);
        $this->assertSame(IdentityStatus::Unverified, $student->fresh()->identity_status);
    }

    public function test_login_attempts_do_not_consume_registration_quota(): void
    {
        $student = Domain::user(UserRole::Student);
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => $student->email, 'password' => 'Password123!'])
                ->assertOk();
        }

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Akun Fiktif Limiter', 'email' => 'limit-register@example.test',
            'nim' => 'AUDIT-LIMITER', 'password' => 'AuditPassword123!',
            'password_confirmation' => 'AuditPassword123!',
        ])->assertCreated();
    }
}
