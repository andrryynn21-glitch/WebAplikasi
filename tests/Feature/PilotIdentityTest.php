<?php

namespace Tests\Feature;

use App\Enums\IdentityStatus;
use App\Enums\UserRole;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\IdentityVerification;
use App\Models\Report;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\IdentityVerificationService;
use App\Services\NotificationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Domain;
use Tests\TestCase;

class PilotIdentityTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Domain::seedCatalog();
        Storage::fake('private');
    }

    private function applicant(): User
    {
        return Domain::user(UserRole::Student, [
            'identity_status' => IdentityStatus::Unverified,
            'name' => 'Registered Fictional Name', 'nim' => 'REGISTERED-001',
        ]);
    }

    private function submit(User $user, string $nim = 'CANDIDATE-001', string $name = 'Candidate Fictional Name'): IdentityVerification
    {
        return app(IdentityVerificationService::class)->submit($user, UploadedFile::fake()->image('fictional-ktm.png'), $nim, $name);
    }

    private function approve(IdentityVerification $candidate, ?User $reviewer = null, ?string $note = null): IdentityVerification
    {
        return app(IdentityVerificationService::class)->approve($candidate, $reviewer ?? Domain::user(UserRole::IdentityVerifier), $note);
    }

    public function test_first_submission_is_only_a_candidate_and_ignores_privileged_payload(): void
    {
        $user = $this->applicant();
        $other = $this->applicant();
        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/identity/submissions', [
            'ktm' => UploadedFile::fake()->image('fictional-ktm.png'),
            'nim' => 'CANDIDATE-NEW', 'full_name' => 'New Candidate Name',
            'user_id' => $other->id, 'status' => 'verified', 'retains_verified' => true,
            'previous_approved_id' => 123, 'reviewed_by' => $other->id,
        ])->assertCreated()->assertJsonPath('data.status', 'pending_verification');

        $candidate = IdentityVerification::findOrFail($response->json('data.id'));
        $this->assertSame($user->id, $candidate->user_id);
        $this->assertFalse($candidate->retains_verified);
        $this->assertNull($candidate->previous_approved_id);
        $this->assertNull($candidate->reviewed_by);
        $this->assertSame('no_approval', $candidate->before_snapshot['history_state']);
        $this->assertSame('Registered Fictional Name', $user->fresh()->name);
        $this->assertSame('REGISTERED-001', $user->fresh()->nim);
        $this->assertFalse($user->fresh()->isVerified());
        $this->getJson('/api/v1/identity/status')->assertOk()
            ->assertJsonPath('data.identity_status', 'pending_verification')
            ->assertJsonPath('data.pending_correction', false)
            ->assertJsonPath('data.pending_submission_id', $candidate->id)
            ->assertJsonPath('data.approved_submission_id', null);
    }

    public function test_first_approval_binds_candidate_name_and_nim_and_records_private_review(): void
    {
        $user = $this->applicant();
        $candidate = $this->submit($user);
        $reviewer = Domain::user(UserRole::IdentityVerifier);
        $this->actingAs($reviewer, 'sanctum')->postJson('/api/v1/identity-verifications/'.$candidate->id.'/approve', [
            'note' => 'Private fictional review note',
        ])->assertOk();

        $this->assertTrue($user->fresh()->isVerified());
        $this->assertSame($candidate->nim, $user->fresh()->nim);
        $this->assertSame($candidate->full_name, $user->fresh()->name);
        $candidate->refresh();
        $this->assertSame($reviewer->id, $candidate->reviewed_by);
        $this->assertNotNull($candidate->reviewed_at);
        $this->assertSame('Private fictional review note', $candidate->review_note);
        $this->assertSame($candidate->id, IdentityVerification::recordedApprovalIdFor($user));
    }

    public function test_first_rejection_remains_unverified_and_preserves_registered_fields(): void
    {
        $user = $this->applicant();
        $candidate = $this->submit($user);
        app(IdentityVerificationService::class)->reject($candidate, Domain::user(UserRole::IdentityVerifier), 'Fictional rejection reason');

        $this->assertSame(IdentityStatus::Rejected, $user->fresh()->identity_status);
        $this->assertSame('REGISTERED-001', $user->fresh()->nim);
        $this->assertSame('Registered Fictional Name', $user->fresh()->name);
        $this->assertNull(IdentityVerification::recordedApprovalIdFor($user));
        $resubmission = $this->submit($user);
        $this->assertFalse($resubmission->retains_verified);
        $this->assertSame(IdentityStatus::PendingVerification, $user->fresh()->identity_status);
    }

    public function test_pending_and_rejected_correction_preserve_verified_access_and_report_ownership(): void
    {
        $user = $this->applicant();
        $approved = $this->approve($this->submit($user));
        $membership = Domain::member($user->fresh(), Domain::classRoom());
        $this->actingAs($user->fresh(), 'sanctum')->postJson('/api/v1/reports', [
            'category_id' => Domain::category('facilities')->id,
            'title' => 'Fictional report before identity correction',
            'description' => 'A fictional report to check stable ownership across identity corrections.',
        ])->assertCreated();
        $report = Report::query()->where('reporter_id', $user->id)->firstOrFail();
        $correction = $this->submit($user, 'CORRECTION-002', 'Corrected Fictional Name');

        $this->assertTrue($user->fresh()->canUseClassFeatures());
        $this->assertSame($approved->nim, $user->fresh()->nim);
        $this->assertSame($approved->full_name, $user->fresh()->name);
        $this->assertTrue($correction->retains_verified);
        $this->assertSame($approved->id, $correction->previous_approved_id);
        $this->actingAs($user->fresh(), 'sanctum')->getJson('/api/v1/identity/status')
            ->assertJsonPath('data.identity_status', 'verified')
            ->assertJsonPath('data.pending_correction', true)
            ->assertJsonPath('data.pending_submission_id', $correction->id)
            ->assertJsonPath('data.approved_submission_id', $approved->id);

        app(IdentityVerificationService::class)->reject($correction, Domain::user(UserRole::IdentityVerifier), 'Private correction rejection');
        $this->assertTrue($user->fresh()->canUseClassFeatures());
        $this->assertSame($approved->nim, $user->fresh()->nim);
        $this->assertSame($approved->full_name, $user->fresh()->name);
        $this->assertSame($user->id, $report->fresh()->reporter_id);
        $this->assertSame($approved->nim, $membership->fresh()->nim);
        $this->actingAs($user->fresh(), 'sanctum')->getJson('/api/v1/identity/status')
            ->assertJsonPath('data.identity_status', 'verified')
            ->assertJsonPath('data.pending_submission_id', null)
            ->assertJsonPath('data.latest_submission.status', 'rejected');
        $this->getJson('/api/v1/reports/'.$report->public_ref)->assertOk();
    }

    public function test_repeated_corrections_keep_the_approved_chain_and_all_private_files(): void
    {
        $user = $this->applicant();
        $first = $this->approve($this->submit($user));
        $rejected = $this->submit($user, 'REJECTED-002');
        app(IdentityVerificationService::class)->reject($rejected, Domain::user(UserRole::IdentityVerifier), 'Private reason for rejected correction');
        $second = $this->approve($this->submit($user, 'APPROVED-003', 'Second Approved Name'));
        $third = $this->submit($user, 'CANDIDATE-004');

        $this->assertSame($first->id, $rejected->previous_approved_id);
        $this->assertSame($first->id, $second->previous_approved_id);
        $this->assertSame($second->id, $third->previous_approved_id);
        $this->assertSame('APPROVED-003', $third->before_snapshot['nim']);
        $this->assertSame('Second Approved Name', $user->fresh()->name);
        $this->assertSame(IdentityStatus::Verified, $first->fresh()->status);
        $this->assertDatabaseCount('identity_verifications', 4);
        foreach ([$first, $rejected, $second, $third] as $version) {
            Storage::disk('private')->assertExists($version->ktm_path);
        }
    }

    public function test_duplicate_pending_and_stale_decision_cannot_overwrite_a_new_candidate(): void
    {
        $user = $this->applicant();
        $first = $this->submit($user);
        $this->assertThrows(fn () => $this->submit($user), ValidationException::class);
        $this->assertCount(1, Storage::disk('private')->allFiles());
        $this->approve($first);
        $next = $this->submit($user, 'NEXT-002');
        $this->assertThrows(fn () => app(IdentityVerificationService::class)->reject($first, Domain::user(UserRole::IdentityVerifier), 'Stale decision attempt'), ValidationException::class);
        $this->assertSame(IdentityStatus::PendingVerification, $next->fresh()->status);
        $this->assertSame($first->nim, $user->fresh()->nim);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    #[TestWith(['approve'])]
    #[TestWith(['reject'])]
    public function test_revoked_reviewer_is_reloaded_before_decision(string $decision): void
    {
        $user = $this->applicant();
        $candidate = $this->submit($user);
        $reviewer = Domain::user(UserRole::IdentityVerifier);
        $reviewer->load('role');
        User::query()->whereKey($reviewer->id)->update(['role_id' => Role::where('key', UserRole::Student->value)->value('id')]);

        $this->assertThrows(fn () => app(IdentityVerificationService::class)->{$decision}($candidate, $reviewer, 'Fictional review decision'), AuthorizationException::class);
        $this->assertSame(IdentityStatus::PendingVerification, $candidate->fresh()->status);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_self_review_is_denied_even_if_role_now_has_review_permission(): void
    {
        $user = $this->applicant();
        $candidate = $this->submit($user);
        User::query()->whereKey($user->id)->update(['role_id' => Role::where('key', UserRole::IdentityVerifier->value)->value('id')]);
        $this->assertThrows(fn () => $this->approve($candidate, $user), AuthorizationException::class);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    #[TestWith(['reviewer'])]
    #[TestWith(['target'])]
    public function test_suspension_blocks_review_using_stale_objects(string $suspended): void
    {
        $user = $this->applicant();
        $candidate = $this->submit($user);
        $reviewer = Domain::user(UserRole::IdentityVerifier);
        User::query()->whereKey($suspended === 'reviewer' ? $reviewer->id : $user->id)->update(['is_active' => false]);
        $this->assertThrows(fn () => $this->approve($candidate, $reviewer), AuthorizationException::class);
        $this->assertSame(IdentityStatus::PendingVerification, $candidate->fresh()->status);
    }

    public function test_suspended_submitter_is_refreshed_before_upload(): void
    {
        $user = $this->applicant();
        User::query()->whereKey($user->id)->update(['is_active' => false]);
        $this->assertThrows(fn () => $this->submit($user), HttpException::class);
        $this->assertDatabaseCount('identity_verifications', 0);
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_changed_target_state_is_not_overwritten_by_pending_decision(): void
    {
        $user = $this->applicant();
        $candidate = $this->submit($user);
        User::query()->whereKey($user->id)->update(['nim' => 'CHANGED-OUTSIDE-TRANSITION']);
        $this->assertThrows(fn () => $this->approve($candidate), ValidationException::class);
        $this->assertSame('CHANGED-OUTSIDE-TRANSITION', $user->fresh()->nim);
        $this->assertSame(IdentityStatus::PendingVerification, $candidate->fresh()->status);
    }

    #[TestWith(['audit', 'approve'])]
    #[TestWith(['notification', 'approve'])]
    #[TestWith(['audit', 'reject'])]
    #[TestWith(['notification', 'reject'])]
    public function test_decision_side_effect_failure_rolls_back_every_database_change(string $failure, string $decision): void
    {
        $user = $this->applicant();
        $approved = $this->approve($this->submit($user));
        $candidate = $this->submit($user, 'ROLLBACK-CANDIDATE', 'Rollback Candidate Name');
        $reviewer = Domain::user(UserRole::IdentityVerifier);
        $this->mock($failure === 'audit' ? AuditLogService::class : NotificationService::class)
            ->shouldReceive($failure === 'audit' ? 'record' : 'send')->once()->andThrow(new RuntimeException('Injected fictional failure'));

        $this->assertThrows(fn () => app(IdentityVerificationService::class)->{$decision}($candidate, $reviewer, 'Private rollback note'), RuntimeException::class);
        $this->assertSame(IdentityStatus::PendingVerification, $candidate->fresh()->status);
        $this->assertNull($candidate->fresh()->review_note);
        $this->assertNull($candidate->fresh()->reviewed_by);
        $this->assertTrue($user->fresh()->isVerified());
        $this->assertSame($approved->nim, $user->fresh()->nim);
        $this->assertSame($approved->full_name, $user->fresh()->name);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseCount('notifications', 1);
        Storage::disk('private')->assertExists($candidate->ktm_path);
    }

    public function test_candidate_metadata_failure_removes_only_the_uncommitted_upload(): void
    {
        $user = $this->applicant();
        $approved = $this->approve($this->submit($user));
        IdentityVerification::updating(function () {
            throw new RuntimeException('Injected candidate metadata failure');
        });
        try {
            $this->assertThrows(fn () => $this->submit($user), RuntimeException::class);
        } finally {
            IdentityVerification::flushEventListeners();
        }
        $this->assertDatabaseCount('identity_verifications', 1);
        $this->assertCount(1, Storage::disk('private')->allFiles());
        Storage::disk('private')->assertExists($approved->ktm_path);
        $this->assertSame($approved->nim, $user->fresh()->nim);
    }

    public function test_private_history_is_not_in_global_audit_notifications_or_serialized_snapshots(): void
    {
        $user = $this->applicant();
        $candidate = $this->submit($user, 'PRIVATE-NIM-CANARY', 'Private Name Canary');
        $this->approve($candidate, null, 'PRIVATE-REVIEW-CANARY');
        $rejected = $this->submit($user, 'PRIVATE-REJECTED-NIM');
        app(IdentityVerificationService::class)->reject($rejected, Domain::user(UserRole::IdentityVerifier), 'PRIVATE-REJECTION-CANARY');
        $global = AuditLog::all()->toJson().AppNotification::all()->toJson();
        foreach (['PRIVATE-NIM-CANARY', 'Private Name Canary', 'PRIVATE-REVIEW-CANARY', 'PRIVATE-REJECTED-NIM', 'PRIVATE-REJECTION-CANARY', $candidate->ktm_path, $candidate->ktm_checksum] as $private) {
            $this->assertStringNotContainsString($private, $global);
        }
        $this->assertArrayNotHasKey('before_snapshot', $rejected->toArray());
        $this->assertArrayNotHasKey('review_note', $rejected->toArray());
        $this->assertSame('PRIVATE-NIM-CANARY', $rejected->before_snapshot['nim']);
        $this->assertSame('PRIVATE-REJECTION-CANARY', $rejected->fresh()->review_note);
        $this->actingAs($user->fresh(), 'sanctum')->getJson('/api/v1/identity/status')->assertOk()
            ->assertJsonPath('data.latest_submission.rejection_reason', 'PRIVATE-REJECTION-CANARY')
            ->assertJsonMissingPath('data.latest_submission.before_snapshot');
        $this->actingAs(Domain::user(UserRole::SuperAdmin), 'sanctum')->getJson('/api/v1/identity-verifications')->assertForbidden();
    }

    public function test_legacy_verified_without_approval_is_preserved_but_never_invented_as_history(): void
    {
        $user = Domain::user(UserRole::Student, ['name' => 'Legacy Stored Name', 'nim' => 'LEGACY-STORED']);
        $candidate = $this->submit($user, 'LEGACY-CORRECTION');
        $this->assertTrue($candidate->retains_verified);
        $this->assertNull($candidate->previous_approved_id);
        $this->assertSame('unresolved_legacy', $candidate->before_snapshot['history_state']);
        $this->assertTrue($user->fresh()->isVerified());
        $this->assertSame('LEGACY-STORED', $user->fresh()->nim);
        $this->actingAs($user->fresh(), 'sanctum')->getJson('/api/v1/identity/status')
            ->assertJsonPath('data.history_state', 'unresolved_legacy')
            ->assertJsonPath('data.approved_submission_id', null);
        app(IdentityVerificationService::class)->reject($candidate, Domain::user(UserRole::IdentityVerifier), 'Legacy correction rejected');
        $this->assertTrue($user->fresh()->isVerified());
        $this->assertNull(IdentityVerification::recordedApprovalIdFor($user));
        $newApproval = $this->approve($this->submit($user, 'NEW-REVIEWED-LEGACY'));
        $next = $this->submit($user, 'NEXT-LEGACY-CANDIDATE');
        $this->assertSame($newApproval->id, $next->previous_approved_id);
        $this->assertSame('unresolved_legacy', $next->before_snapshot['history_state']);
        $this->actingAs($user->fresh(), 'sanctum')->getJson('/api/v1/identity/status')
            ->assertJsonPath('data.history_state', 'unresolved_legacy')
            ->assertJsonPath('data.approved_submission_id', $newApproval->id);
    }

    public function test_ambiguous_legacy_pending_is_flagged_and_not_automatically_reviewed_or_backfilled(): void
    {
        $user = $this->applicant();
        $user->forceFill(['identity_status' => IdentityStatus::PendingVerification, 'nim' => 'AMBIGUOUS-STORED'])->save();
        $legacy = IdentityVerification::create([
            'user_id' => $user->id, 'nim' => 'LEGACY-CANDIDATE', 'full_name' => 'Legacy Candidate',
            'ktm_path' => 'identity/fictional-legacy-not-read.png', 'status' => IdentityStatus::PendingVerification,
        ]);
        $this->assertThrows(fn () => $this->approve($legacy), ValidationException::class);
        $this->assertThrows(fn () => app(IdentityVerificationService::class)->reject($legacy, Domain::user(UserRole::IdentityVerifier), 'Cannot invent old state'), ValidationException::class);
        $this->assertNull($legacy->fresh()->retains_verified);
        $this->assertNull($legacy->fresh()->before_snapshot);
        $this->assertSame('AMBIGUOUS-STORED', $user->fresh()->nim);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/identity/status')
            ->assertJsonPath('data.history_state', 'unresolved_legacy')
            ->assertJsonPath('data.latest_submission.requires_legacy_reconciliation', true);
    }

    public function test_matching_nim_and_hash_are_not_an_unapproved_global_rejection_rule(): void
    {
        $file = UploadedFile::fake()->image('fictional-shared.png');
        $first = $this->applicant();
        $second = $this->applicant();
        $service = app(IdentityVerificationService::class);
        $a = $service->submit($first, $file, 'SAME-FICTIONAL-NIM', 'First Fictional Applicant');
        $b = $service->submit($second, $file, 'SAME-FICTIONAL-NIM', 'Second Fictional Applicant');
        $this->assertSame($a->ktm_checksum, $b->ktm_checksum);
        $this->assertSame(IdentityStatus::PendingVerification, $a->status);
        $this->assertSame(IdentityStatus::PendingVerification, $b->status);
        $this->assertSame($first->id, $a->user_id);
        $this->assertSame($second->id, $b->user_id);
        $this->actingAs($second->fresh(), 'sanctum')->getJson('/api/v1/identity/status')
            ->assertJsonPath('data.latest_submission.id', $b->id)
            ->assertJsonMissingPath('data.matches');
    }
}
