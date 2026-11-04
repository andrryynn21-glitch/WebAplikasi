<?php

namespace Tests\Feature;

use App\Enums\AttachmentType;
use App\Enums\AuditAction;
use App\Enums\IdentityStatus;
use App\Enums\MembershipStatus;
use App\Enums\NotificationType;
use App\Enums\SecurityEventCategory;
use App\Enums\UserRole;
use App\Models\AppNotification;
use App\Models\Aspiration;
use App\Models\AspirationComment;
use App\Models\ClassMembership;
use App\Models\IdentityVerification;
use App\Models\Role;
use App\Services\AuditLogService;
use App\Services\IdentityVerificationService;
use App\Services\NotificationService;
use App\Services\PrivateStorageService;
use App\Services\RoleAssignmentService;
use App\Services\SecurityEventService;
use App\Services\StatisticsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\Domain;
use Tests\TestCase;

class NonReportSecurityAuditTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Domain::seedCatalog();
    }

    public function test_current_class_leader_can_approve_pending_membership(): void
    {
        $class = Domain::classRoom();
        $leader = Domain::user(UserRole::ClassLeader);
        Domain::leader($leader, $class);
        $student = Domain::user(UserRole::Student);
        $membership = ClassMembership::create(['user_id' => $student->id, 'class_id' => $class->id, 'status' => 'pending']);

        $this->actingAs($leader, 'sanctum')->postJson('/api/v1/memberships/'.$membership->id.'/decide', [
            'decision' => 'approve',
        ])->assertOk()->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('class_memberships', ['id' => $membership->id, 'status' => 'active', 'approved_by' => $leader->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'membership_approved', 'subject_id' => $membership->id]);
        $this->assertDatabaseHas('notifications', ['user_id' => $student->id, 'type' => 'membership_decision']);
    }

    public function test_membership_review_is_class_scoped(): void
    {
        $class = Domain::classRoom();
        $leader = Domain::user(UserRole::ClassLeader);
        Domain::leader($leader, $class);
        $otherClass = Domain::classRoom();
        $student = Domain::user(UserRole::Student);
        $membership = ClassMembership::create(['user_id' => $student->id, 'class_id' => $otherClass->id, 'status' => 'pending']);

        $this->actingAs($leader, 'sanctum')->postJson('/api/v1/memberships/'.$membership->id.'/decide', [
            'decision' => 'approve',
        ])->assertForbidden();

        $this->assertDatabaseHas('class_memberships', ['id' => $membership->id, 'status' => 'pending']);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_former_class_leader_cannot_review_or_moderate(): void
    {
        $class = Domain::classRoom();
        $leader = Domain::user(UserRole::ClassLeader);
        $assignment = Domain::leader($leader, $class);
        $assignment->forceFill(['ends_at' => now()])->save();
        $student = Domain::user(UserRole::Student);
        $membership = ClassMembership::create(['user_id' => $student->id, 'class_id' => $class->id, 'status' => 'pending']);
        $aspiration = Aspiration::create(['class_id' => $class->id, 'author_id' => $student->id, 'title' => 'Suggestion', 'body' => 'Class suggestion.']);

        $this->assertFalse($leader->can('review', $membership));
        $this->assertFalse($leader->can('moderate', $aspiration));
        $this->actingAs($leader, 'sanctum')->postJson('/api/v1/aspirations/'.$aspiration->id.'/moderate', [
            'action' => 'hide', 'reason' => 'Reason for moderation.',
        ])->assertForbidden();

        $this->assertDatabaseHas('aspirations', ['id' => $aspiration->id, 'status' => 'published']);
        $this->assertDatabaseCount('moderation_actions', 0);
    }

    public function test_membership_decision_cannot_be_overwritten(): void
    {
        $class = Domain::classRoom();
        $admin = Domain::user(UserRole::SuperAdmin);
        $student = Domain::user(UserRole::Student);
        $membership = Domain::member($student, $class);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/memberships/'.$membership->id.'/decide', [
            'decision' => 'reject', 'note' => 'Cannot overwrite approval.',
        ])->assertUnprocessable();

        $this->assertDatabaseHas('class_memberships', ['id' => $membership->id, 'status' => 'active']);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_leader_assignment_requires_verified_membership_in_target_class(): void
    {
        $admin = Domain::user(UserRole::SuperAdmin);
        $class = Domain::classRoom();
        $student = Domain::user(UserRole::Student, ['identity_status' => IdentityStatus::PendingVerification]);
        Domain::member($student, $class);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/classes/'.$class->id.'/leader', [
            'user_id' => $student->id,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('class_administrator_assignments', 0);
        $this->assertSame(UserRole::Student, $student->fresh()->roleKey());
    }

    public function test_role_assignment_requires_authority_even_when_called_directly(): void
    {
        $student = Domain::user(UserRole::Student);
        $target = Domain::user(UserRole::Student);

        $this->assertThrows(fn () => app(RoleAssignmentService::class)->assign($target, UserRole::SuperAdmin, $student), AuthorizationException::class);

        $this->assertSame(UserRole::Student, $target->fresh()->roleKey());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_profile_payload_cannot_change_identity_role_or_membership(): void
    {
        $student = Domain::user(UserRole::Student, ['nim' => 'VERIFIED-NIM']);

        $this->actingAs($student, 'sanctum')->patchJson('/api/v1/profile', [
            'name' => $student->name, 'role_id' => Role::where('key', 'super_admin')->value('id'),
            'identity_status' => 'rejected', 'nim' => 'FAKE-NIM', 'class_id' => 123,
        ])->assertOk()->assertJsonPath('data.name', $student->name);

        $student->refresh();
        $this->assertSame(UserRole::Student, $student->roleKey());
        $this->assertSame(IdentityStatus::Verified, $student->identity_status);
        $this->assertSame('VERIFIED-NIM', $student->nim);
        $this->assertDatabaseCount('class_memberships', 0);
    }

    public function test_identity_decision_rechecks_persisted_state(): void
    {
        Storage::fake('private');
        $student = Domain::user(UserRole::Student, ['nim' => 'ID-1']);
        $reviewer = Domain::user(UserRole::IdentityVerifier);
        $service = app(IdentityVerificationService::class);
        $verification = $service->submit($student, UploadedFile::fake()->image('ktm.jpg'), 'ID-1', 'Student Name');
        $stale = IdentityVerification::findOrFail($verification->id);
        $service->approve($verification, $reviewer);

        $this->assertThrows(fn () => $service->reject($stale, $reviewer, 'Stale rejection.'), ValidationException::class);

        $this->assertDatabaseHas('identity_verifications', ['id' => $verification->id, 'status' => 'verified']);
        $this->assertSame(IdentityStatus::Verified, $student->fresh()->identity_status);
        $this->assertDatabaseCount('audit_logs', 1);
        Storage::disk('private')->assertExists($verification->ktm_path);
    }

    public function test_unverified_dashboard_does_not_expose_class_aspirations(): void
    {
        $class = Domain::classRoom();
        $student = Domain::user(UserRole::Student, ['identity_status' => IdentityStatus::PendingVerification]);
        Domain::member($student, $class);
        Aspiration::create(['class_id' => $class->id, 'author_id' => $student->id, 'title' => 'CLASS-SECRET', 'body' => 'Private class discussion.']);

        $this->actingAs($student, 'sanctum')->getJson('/api/v1/dashboard')
            ->assertOk()->assertJsonPath('data.aspirations', [])->assertDontSee('CLASS-SECRET');

        $this->assertSame([], $student->accessibleClassIds());
    }

    public function test_assigned_lecturer_can_moderate_without_student_membership(): void
    {
        $class = Domain::classRoom();
        $lecturer = Domain::user(UserRole::LecturerCoordinator);
        Domain::lecturer($lecturer, $class);
        $student = Domain::user(UserRole::Student);
        $aspiration = Aspiration::create(['class_id' => $class->id, 'author_id' => $student->id, 'title' => 'Suggestion', 'body' => 'Class suggestion.']);

        $this->actingAs($lecturer, 'sanctum')->postJson('/api/v1/aspirations/'.$aspiration->id.'/moderate', [
            'action' => 'hide', 'reason' => 'Reason for moderation.',
        ])->assertOk()->assertJsonPath('data.resulting_status', 'hidden');

        $this->assertDatabaseHas('aspirations', ['id' => $aspiration->id, 'status' => 'hidden']);
        $this->assertDatabaseHas('moderation_actions', ['moderator_id' => $lecturer->id, 'moderatable_id' => $aspiration->id]);
    }

    public function test_moderation_cannot_resolve_flag_from_other_class(): void
    {
        $class = Domain::classRoom();
        $leader = Domain::user(UserRole::ClassLeader);
        Domain::leader($leader, $class);
        $student = Domain::user(UserRole::Student);
        $aspiration = Aspiration::create(['class_id' => $class->id, 'author_id' => $student->id, 'title' => 'Suggestion', 'body' => 'Class suggestion.']);
        $other = Aspiration::create(['class_id' => Domain::classRoom()->id, 'author_id' => $student->id, 'title' => 'Other', 'body' => 'Other class content.']);
        $flag = $other->contentFlags()->create(['reported_by' => $leader->id, 'reason' => 'spam']);

        $this->actingAs($leader, 'sanctum')->postJson('/api/v1/aspirations/'.$aspiration->id.'/moderate', [
            'action' => 'hide', 'reason' => 'Reason for moderation.', 'flag_id' => $flag->id,
        ])->assertUnprocessable();

        $this->assertDatabaseHas('content_flags', ['id' => $flag->id, 'status' => 'open']);
        $this->assertDatabaseHas('aspirations', ['id' => $aspiration->id, 'status' => 'published']);
        $this->assertDatabaseCount('moderation_actions', 0);
    }

    public function test_dismissing_flag_does_not_change_content_status(): void
    {
        $class = Domain::classRoom();
        $leader = Domain::user(UserRole::ClassLeader);
        Domain::leader($leader, $class);
        $student = Domain::user(UserRole::Student);
        $aspiration = Aspiration::create(['class_id' => $class->id, 'author_id' => $student->id, 'title' => 'Suggestion', 'body' => 'Class suggestion.']);
        $flag = $aspiration->contentFlags()->create(['reported_by' => $leader->id, 'reason' => 'spam']);

        $this->actingAs($leader, 'sanctum')->postJson('/api/v1/aspirations/'.$aspiration->id.'/moderate', [
            'action' => 'dismiss_flag', 'reason' => 'Content follows class rules.', 'flag_id' => $flag->id,
        ])->assertOk()->assertJsonPath('data.resulting_status', null);

        $this->assertDatabaseHas('content_flags', ['id' => $flag->id, 'status' => 'dismissed']);
        $this->assertDatabaseHas('aspirations', ['id' => $aspiration->id, 'status' => 'published']);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_locked_aspiration_remains_readable(): void
    {
        $class = Domain::classRoom();
        $student = Domain::user(UserRole::Student);
        Domain::member($student, $class);
        $author = Domain::user(UserRole::Student);
        $aspiration = Aspiration::create(['class_id' => $class->id, 'author_id' => $author->id, 'title' => 'Locked discussion', 'body' => 'This remains visible.']);
        $aspiration->forceFill(['status' => 'locked'])->save();

        $this->actingAs($student, 'sanctum')->getJson('/api/v1/aspirations/'.$aspiration->id)
            ->assertOk()->assertJsonPath('data.body', 'This remains visible.');

        $this->assertFalse($student->can('comment', $aspiration));
    }

    public function test_aspiration_attachment_uploader_loses_access_when_membership_revoked(): void
    {
        Storage::fake('private');
        $class = Domain::classRoom();
        $student = Domain::user(UserRole::Student);
        $membership = Domain::member($student, $class);
        $aspiration = Aspiration::create(['class_id' => $class->id, 'author_id' => $student->id, 'title' => 'Suggestion', 'body' => 'Class suggestion.']);
        $attachment = app(PrivateStorageService::class)->storeAttachment(UploadedFile::fake()->image('image.jpg'), $aspiration, $student, AttachmentType::AspirationImage);
        $membership->forceFill(['status' => MembershipStatus::Revoked])->save();

        $this->actingAs($student, 'sanctum')->getJson('/api/v1/attachments/'.$attachment->id)->assertForbidden();

        $this->assertFalse($student->can('delete', $attachment));
        Storage::disk('private')->assertExists($attachment->path);
    }

    public function test_aspiration_and_comment_flag_statistics_are_class_scoped(): void
    {
        $class = Domain::classRoom();
        $leader = Domain::user(UserRole::ClassLeader);
        Domain::leader($leader, $class);
        $student = Domain::user(UserRole::Student);
        foreach ([$class, Domain::classRoom()] as $flagClass) {
            $aspiration = Aspiration::create(['class_id' => $flagClass->id, 'author_id' => $student->id, 'title' => 'Suggestion', 'body' => 'Class suggestion.']);
            $aspiration->contentFlags()->create(['reported_by' => $leader->id, 'reason' => 'spam']);
            $comment = $aspiration->comments()->create(['author_id' => $student->id, 'body' => 'Comment.']);
            $comment->contentFlags()->create(['reported_by' => $leader->id, 'reason' => 'spam']);
        }

        $summary = app(StatisticsService::class)->aspirationSummary($leader);

        $this->assertSame(2, $summary['open_flags']);
        $this->assertSame(1, $summary['total_aspirations']);
    }

    public function test_notification_payload_accepts_only_scalar_references(): void
    {
        $student = Domain::user(UserRole::Student);

        $notification = app(NotificationService::class)->send($student, NotificationType::SecurityNotice, 'Notice', null, [
            'security_event_id' => 12, 'description' => 'SECRET', 'nested' => ['token' => 'SECRET'],
            'report_ref' => ['body' => 'SECRET'], 'reporter_email' => 'SECRET',
        ]);

        $this->assertSame(['resource' => 'security_event', 'purpose' => 'staff', 'security_event_id' => 12], $notification->data);
    }

    public function test_nested_audit_secrets_are_removed(): void
    {
        $admin = Domain::user(UserRole::SuperAdmin);

        $log = app(AuditLogService::class)->record(AuditAction::RoleAssigned, $admin, null, null, [
            'from' => 'student', 'before' => ['password' => 'SECRET', 'body' => 'SECRET', 'status' => 'verified'],
        ]);

        $this->assertSame(['from' => 'student', 'before' => ['status' => 'verified']], $log->changes);
    }

    public function test_class_notifications_are_hidden_after_membership_revocation(): void
    {
        $student = Domain::user(UserRole::Student);
        $class = Domain::classRoom();
        $membership = Domain::member($student, $class);
        $notification = app(NotificationService::class)->send($student, NotificationType::AspirationComment, 'Class notice', null, ['class_id' => $class->id]);
        $membership->forceFill(['status' => MembershipStatus::Revoked])->save();

        $this->actingAs($student, 'sanctum')->getJson('/api/v1/notifications')
            ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.unread_count', 0);

        $this->assertFalse($student->can('update', $notification));
    }

    public function test_security_events_and_delivery_are_scoped_to_assigned_class(): void
    {
        $class = Domain::classRoom();
        $otherClass = Domain::classRoom();
        $leader = Domain::user(UserRole::ClassLeader);
        $otherLeader = Domain::user(UserRole::ClassLeader);
        Domain::leader($leader, $class);
        Domain::leader($otherLeader, $otherClass);
        $event = app(SecurityEventService::class)->record(SecurityEventCategory::PrivateFileAccessAttempt, null, null, null, 'Denied', $class->id,
            ['body' => 'SECRET', 'token' => 'SECRET', 'status' => 'denied']);
        app(SecurityEventService::class)->record(SecurityEventCategory::PrivateFileAccessAttempt, null, null, null, 'Denied', $otherClass->id);

        $this->actingAs($leader, 'sanctum')->getJson('/api/v1/security-events')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $event->id);

        $this->assertFalse($otherLeader->can('view', $event));
        $this->assertSame(['status' => 'denied'], $event->context);
        $this->assertSame(1, AppNotification::where('user_id', $leader->id)->count());
        $this->assertSame(1, AppNotification::where('user_id', $otherLeader->id)->count());
    }

    public function test_class_patch_accepts_partial_payload(): void
    {
        $admin = Domain::user(UserRole::SuperAdmin);
        $class = Domain::classRoom();

        $this->actingAs($admin, 'sanctum')->patchJson('/api/v1/admin/classes/'.$class->id, ['name' => 'Updated class'])
            ->assertOk()->assertJsonPath('data.name', 'Updated class');

        $this->assertDatabaseHas('classes', ['id' => $class->id, 'name' => 'Updated class', 'code' => $class->code]);
    }

    public function test_duplicate_class_code_returns_validation_error(): void
    {
        $admin = Domain::user(UserRole::SuperAdmin);
        $existing = Domain::classRoom();
        $class = Domain::classRoom();

        $this->actingAs($admin, 'sanctum')->patchJson('/api/v1/admin/classes/'.$class->id, ['code' => $existing->code])
            ->assertUnprocessable();

        $this->assertDatabaseHas('classes', ['id' => $class->id, 'code' => $class->code]);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_class_filter_cannot_expose_other_class_aspirations(): void
    {
        $student = Domain::user(UserRole::Student);
        Domain::member($student, Domain::classRoom());
        $otherClass = Domain::classRoom();

        $this->actingAs($student, 'sanctum')->getJson('/api/v1/aspirations?class_id='.$otherClass->id)
            ->assertForbidden()->assertJsonPath('error.code', 'CLASS_ACCESS_DENIED');
    }

    public function test_security_cooldown_blocks_requests_until_expiry(): void
    {
        $this->freezeTime();
        $student = Domain::user(UserRole::Student);
        app(SecurityEventService::class)->startCooldown($student, 'repeated_denials');

        $this->actingAs($student, 'sanctum')->getJson('/api/v1/auth/me')
            ->assertTooManyRequests()->assertJsonPath('error.code', 'SECURITY_COOLDOWN')
            ->assertHeader('Retry-After', '900');

        $this->travel(901)->seconds();
        $this->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_security_cooldown_does_not_prevent_logout_all(): void
    {
        $student = Domain::user(UserRole::Student);
        $student->createToken('test');
        app(SecurityEventService::class)->startCooldown($student, 'repeated_denials');

        $this->actingAs($student, 'sanctum')->postJson('/api/v1/auth/logout-all')->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_flags_list_contains_only_the_aspiration_and_its_comments(): void
    {
        $class = Domain::classRoom();
        $leader = Domain::user(UserRole::ClassLeader);
        Domain::leader($leader, $class);
        $student = Domain::user(UserRole::Student);
        $aspiration = Aspiration::create(['class_id' => $class->id, 'author_id' => $student->id, 'title' => 'Suggestion', 'body' => 'Class suggestion.']);
        $flag = $aspiration->contentFlags()->create(['reported_by' => $leader->id, 'reason' => 'spam']);
        $comment = $aspiration->comments()->create(['author_id' => $student->id, 'body' => 'Flagged comment.']);
        $commentFlag = $comment->contentFlags()->create(['reported_by' => $leader->id, 'reason' => 'harassment', 'note' => 'Review this comment.']);
        foreach ([$class, Domain::classRoom()] as $otherClass) {
            $other = Aspiration::create(['class_id' => $otherClass->id, 'author_id' => $student->id, 'title' => 'Other', 'body' => 'OUTSIDE-CONTENT']);
            $other->contentFlags()->create(['reported_by' => $leader->id, 'reason' => 'spam']);
        }

        $this->actingAs($leader, 'sanctum')->getJson('/api/v1/aspirations/'.$aspiration->id.'/flags')
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $commentFlag->id)
            ->assertJsonPath('data.0.content_type', 'comment')
            ->assertJsonPath('data.0.content_id', $comment->id)
            ->assertJsonPath('data.0.can_moderate', true)
            ->assertJsonPath('data.1.id', $flag->id)
            ->assertJsonPath('meta.pagination.total', 2)
            ->assertDontSee('OUTSIDE-CONTENT')
            ->assertJsonMissingPaths(['data.0.reported_by', 'data.0.reporter']);
    }

    public function test_moderator_can_view_flags_on_own_aspiration_but_cannot_moderate_it(): void
    {
        $class = Domain::classRoom();
        $leader = Domain::user(UserRole::ClassLeader);
        Domain::leader($leader, $class);
        $student = Domain::user(UserRole::Student);
        $aspiration = Aspiration::create(['class_id' => $class->id, 'author_id' => $leader->id, 'title' => 'My suggestion', 'body' => 'Class suggestion.']);
        $aspiration->contentFlags()->create(['reported_by' => $student->id, 'reason' => 'spam']);

        $this->actingAs($leader, 'sanctum')->getJson('/api/v1/aspirations/'.$aspiration->id.'/flags')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.can_moderate', false);

        $this->assertFalse($leader->can('moderate', $aspiration));
    }

    public function test_student_can_flag_comment_and_repeated_flag_is_idempotent(): void
    {
        $class = Domain::classRoom();
        $student = Domain::user(UserRole::Student);
        Domain::member($student, $class);
        $author = Domain::user(UserRole::Student);
        $aspiration = Aspiration::create(['class_id' => $class->id, 'author_id' => $author->id, 'title' => 'Suggestion', 'body' => 'Class suggestion.']);
        $comment = $aspiration->comments()->create(['author_id' => $author->id, 'body' => 'Comment to report.']);

        $response = $this->actingAs($student, 'sanctum')->postJson('/api/v1/aspirations/'.$aspiration->id.'/comments/'.$comment->id.'/flag', [
            'reason' => 'harassment', 'note' => 'Review this comment.',
        ])->assertCreated();
        $this->postJson('/api/v1/aspirations/'.$aspiration->id.'/comments/'.$comment->id.'/flag', [
            'reason' => 'spam',
        ])->assertCreated()->assertJsonPath('data.id', $response->json('data.id'));

        $this->assertDatabaseCount('content_flags', 1);
        $this->assertDatabaseHas('content_flags', ['flaggable_type' => AspirationComment::class, 'flaggable_id' => $comment->id,
            'reported_by' => $student->id, 'reason' => 'harassment', 'note' => 'Review this comment.', 'status' => 'open']);
    }

    public function test_comment_hide_and_restore_keep_visible_comment_count_consistent(): void
    {
        $class = Domain::classRoom();
        $leader = Domain::user(UserRole::ClassLeader);
        Domain::leader($leader, $class);
        $student = Domain::user(UserRole::Student);
        $aspiration = Aspiration::create(['class_id' => $class->id, 'author_id' => $student->id, 'title' => 'Suggestion', 'body' => 'Class suggestion.']);
        $comment = $aspiration->comments()->create(['author_id' => $student->id, 'body' => 'Comment to moderate.']);
        $aspiration->comments()->create(['author_id' => $student->id, 'body' => 'Visible comment.']);
        $aspiration->forceFill(['comments_count' => 2])->save();
        $flag = $comment->contentFlags()->create(['reported_by' => $leader->id, 'reason' => 'spam']);

        $this->actingAs($leader, 'sanctum')->postJson('/api/v1/aspirations/'.$aspiration->id.'/comments/'.$comment->id.'/moderate', [
            'action' => 'hide', 'reason' => 'Comment breaks class rules.', 'flag_id' => $flag->id,
        ])->assertOk()->assertJsonPath('data.action', 'hide')->assertJsonPath('data.resulting_status', 'hidden');

        $this->assertDatabaseHas('aspiration_comments', ['id' => $comment->id, 'status' => 'hidden']);
        $this->assertDatabaseHas('aspirations', ['id' => $aspiration->id, 'comments_count' => 1]);
        $this->assertDatabaseHas('content_flags', ['id' => $flag->id, 'status' => 'resolved', 'reviewed_by' => $leader->id]);
        $this->assertDatabaseHas('moderation_actions', ['moderatable_type' => AspirationComment::class, 'moderatable_id' => $comment->id, 'action' => 'hide']);
        $this->assertDatabaseHas('notifications', ['user_id' => $student->id, 'type' => 'content_moderated']);

        $this->postJson('/api/v1/aspirations/'.$aspiration->id.'/comments/'.$comment->id.'/moderate', [
            'action' => 'restore', 'reason' => 'Comment can be restored.',
        ])->assertOk()->assertJsonPath('data.resulting_status', 'published');

        $this->assertDatabaseHas('aspirations', ['id' => $aspiration->id, 'comments_count' => 2]);
    }

    #[TestWith(['flag'])]
    #[TestWith(['moderate'])]
    public function test_comment_routes_reject_mismatched_aspiration_with_not_found(string $action): void
    {
        $class = Domain::classRoom();
        $leader = Domain::user(UserRole::ClassLeader);
        Domain::leader($leader, $class);
        $student = Domain::user(UserRole::Student);
        $aspiration = Aspiration::create(['class_id' => $class->id, 'author_id' => $student->id, 'title' => 'Suggestion', 'body' => 'Class suggestion.']);
        $other = Aspiration::create(['class_id' => $class->id, 'author_id' => $student->id, 'title' => 'Other', 'body' => 'Other suggestion.']);
        $comment = $other->comments()->create(['author_id' => $student->id, 'body' => 'Other comment.']);

        $this->actingAs($leader, 'sanctum')->postJson('/api/v1/aspirations/'.$aspiration->id.'/comments/'.$comment->id.'/'.$action, [
            'reason' => $action === 'flag' ? 'spam' : 'Reason for moderation.', 'action' => 'hide',
        ])->assertNotFound();

        $this->assertDatabaseCount('content_flags', 0);
        $this->assertDatabaseCount('moderation_actions', 0);
        $this->assertDatabaseHas('aspiration_comments', ['id' => $comment->id, 'status' => 'published']);
    }

    public function test_former_leader_cannot_list_flags_or_moderate_comments(): void
    {
        $class = Domain::classRoom();
        $leader = Domain::user(UserRole::ClassLeader);
        $assignment = Domain::leader($leader, $class);
        $assignment->forceFill(['ends_at' => now()])->save();
        $student = Domain::user(UserRole::Student);
        $aspiration = Aspiration::create(['class_id' => $class->id, 'author_id' => $student->id, 'title' => 'Suggestion', 'body' => 'Class suggestion.']);
        $comment = $aspiration->comments()->create(['author_id' => $student->id, 'body' => 'Comment.']);

        $this->actingAs($leader, 'sanctum')->getJson('/api/v1/aspirations/'.$aspiration->id.'/flags')->assertForbidden();
        $this->postJson('/api/v1/aspirations/'.$aspiration->id.'/comments/'.$comment->id.'/moderate', [
            'action' => 'hide', 'reason' => 'Reason for moderation.',
        ])->assertForbidden();

        $this->assertDatabaseCount('moderation_actions', 0);
        $this->assertDatabaseHas('aspiration_comments', ['id' => $comment->id, 'status' => 'published']);
    }

    public function test_comment_moderation_rejects_another_comments_flag(): void
    {
        $class = Domain::classRoom();
        $leader = Domain::user(UserRole::ClassLeader);
        Domain::leader($leader, $class);
        $student = Domain::user(UserRole::Student);
        $aspiration = Aspiration::create(['class_id' => $class->id, 'author_id' => $student->id, 'title' => 'Suggestion', 'body' => 'Class suggestion.']);
        $comment = $aspiration->comments()->create(['author_id' => $student->id, 'body' => 'Comment.']);
        $otherComment = $aspiration->comments()->create(['author_id' => $student->id, 'body' => 'Other comment.']);
        $flag = $otherComment->contentFlags()->create(['reported_by' => $leader->id, 'reason' => 'spam']);

        $this->actingAs($leader, 'sanctum')->postJson('/api/v1/aspirations/'.$aspiration->id.'/comments/'.$comment->id.'/moderate', [
            'action' => 'hide', 'reason' => 'Reason for moderation.', 'flag_id' => $flag->id,
        ])->assertUnprocessable();

        $this->assertDatabaseHas('content_flags', ['id' => $flag->id, 'status' => 'open']);
        $this->assertDatabaseHas('aspiration_comments', ['id' => $comment->id, 'status' => 'published']);
        $this->assertDatabaseCount('moderation_actions', 0);
    }

    public function test_flags_list_denies_students_and_cross_class_moderators(): void
    {
        $class = Domain::classRoom();
        $student = Domain::user(UserRole::Student);
        Domain::member($student, $class);
        $otherLeader = Domain::user(UserRole::ClassLeader);
        Domain::leader($otherLeader, Domain::classRoom());
        $aspiration = Aspiration::create(['class_id' => $class->id, 'author_id' => $student->id, 'title' => 'Suggestion', 'body' => 'Class suggestion.']);

        $this->actingAs($student, 'sanctum')->getJson('/api/v1/aspirations/'.$aspiration->id.'/flags')->assertForbidden();
        $this->actingAs($otherLeader, 'sanctum')->getJson('/api/v1/aspirations/'.$aspiration->id.'/flags')->assertForbidden();
    }

    public function test_moderator_cannot_moderate_own_comment(): void
    {
        $class = Domain::classRoom();
        $leader = Domain::user(UserRole::ClassLeader);
        Domain::leader($leader, $class);
        $student = Domain::user(UserRole::Student);
        $aspiration = Aspiration::create(['class_id' => $class->id, 'author_id' => $student->id, 'title' => 'Suggestion', 'body' => 'Class suggestion.']);
        $comment = $aspiration->comments()->create(['author_id' => $leader->id, 'body' => 'My comment.']);

        $this->actingAs($leader, 'sanctum')->postJson('/api/v1/aspirations/'.$aspiration->id.'/comments/'.$comment->id.'/moderate', [
            'action' => 'hide', 'reason' => 'Reason for moderation.',
        ])->assertForbidden();

        $this->assertDatabaseCount('moderation_actions', 0);
        $this->assertDatabaseHas('aspiration_comments', ['id' => $comment->id, 'status' => 'published']);
    }
}
