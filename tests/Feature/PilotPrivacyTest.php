<?php

namespace Tests\Feature;

use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Models\AppNotification;
use App\Models\ClassInvitationCode;
use App\Models\ClassMembership;
use App\Models\Report;
use App\Models\Role;
use App\Models\User;
use App\Services\CredentialService;
use App\Services\ReportWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\Domain;
use Tests\TestCase;

class PilotPrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Domain::seedCatalog();
    }

    private function createReport(User $reporter, array $extra = []): Report
    {
        $response = $this->actingAs($reporter, 'sanctum')->postJson('/api/v1/reports', array_merge([
            'category_id' => Domain::category('facilities')->id,
            'title' => 'PILOT_PRIVATE_TITLE_CANARY',
            'description' => 'PILOT_PRIVATE_BODY_CANARY: synthetic report for authorization regression only.',
        ], $extra))->assertCreated();

        return Report::query()->where('public_ref', $response->json('data.public_ref'))->firstOrFail();
    }

    public function test_class_list_statistics_and_dashboard_match_detail_conflict_and_subject_policy(): void
    {
        $class = Domain::classRoom();
        $lecturer = Domain::user(UserRole::LecturerCoordinator, ['name' => 'PILOT_SUBJECT_CANARY']);
        Domain::lecturer($lecturer, $class);
        $owner = Domain::user(UserRole::Student, ['name' => 'PILOT_OWNER_CANARY']);
        $owner->forceFill(['nim' => 'PILOT_NIM_CANARY'])->save();
        Domain::member($owner, $class);

        $allowed = $this->createReport($owner);
        $conflict = $this->createReport($owner);
        $conflict->conflictsOfInterest()->create(['user_id' => $lecturer->id, 'status' => 'declared', 'relationship' => 'synthetic']);
        $subject = $this->createReport($owner, ['involved_parties' => ['About PILOT_SUBJECT_CANARY in this synthetic case']]);

        $this->assertTrue($lecturer->can('view', $allowed));
        $this->assertFalse($lecturer->can('view', $conflict));
        $this->assertFalse($lecturer->can('view', $subject));
        $list = $this->actingAs($lecturer, 'sanctum')->getJson('/api/v1/reports?scope=class&per_page=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.public_ref', $allowed->public_ref)->assertJsonPath('data.0.title', null);
        $stats = $this->getJson('/api/v1/reports/statistics?class_id='.$class->id)
            ->assertOk()->assertJsonPath('data.total_reports', 1)->assertJsonPath('data.open_reports', 1)
            ->assertJsonPath('data.by_status.needs_review', 1)->assertJsonPath('data.by_category.facilities', 1);
        $dashboard = $this->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('data.reports.needs_review', 1)->assertJsonPath('data.statistics.total_reports', 1)
            ->assertJsonCount(1, 'data.needs_attention')->assertJsonPath('data.needs_attention.0.public_ref', $allowed->public_ref);
        $notices = $this->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.unread_count', 1);

        foreach ([$list, $stats, $dashboard, $notices] as $response) {
            foreach (['PILOT_OWNER_CANARY', 'PILOT_NIM_CANARY', 'PILOT_PRIVATE_TITLE_CANARY', 'PILOT_PRIVATE_BODY_CANARY',
                $conflict->public_ref, $subject->public_ref] as $canary) {
                $this->assertStringNotContainsString($canary, $response->getContent());
            }
        }
        $this->assertDatabaseMissing('notifications', ['user_id' => $lecturer->id, 'data->report_ref' => $subject->public_ref]);
    }

    #[TestWith(['class_leader', false])]
    #[TestWith(['class_leader', true])]
    #[TestWith(['lecturer_coordinator', false])]
    public function test_real_routing_notices_lose_staff_authority_but_account_notices_survive(string $role, bool $demote): void
    {
        $class = Domain::classRoom();
        $staff = Domain::user(UserRole::from($role));
        if ($role === 'class_leader') {
            $assignment = Domain::leader($staff, $class);
        } else {
            Domain::lecturer($staff, $class);
            $assignment = $class->lecturerAssignments()->firstOrFail();
        }
        $owner = Domain::user(UserRole::Student);
        Domain::member($owner, $class);
        $report = $this->createReport($owner);
        $notice = AppNotification::query()->where('user_id', $staff->id)->where('data->report_ref', $report->public_ref)->firstOrFail();
        $this->assertSame('staff', $notice->data['purpose']);
        $this->assertSame('report', $notice->data['resource']);
        $this->actingAs($staff, 'sanctum')->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(1, 'data');

        app(CredentialService::class)->change($staff, 'Password123!', 'PilotChangedPassword123!');
        $accountNotice = AppNotification::query()->where('user_id', $staff->id)->where('data->resource', 'account')->firstOrFail();
        $assignment->forceFill(['ends_at' => now()])->save();
        if ($demote) {
            $staff->forceFill(['role_id' => Role::query()->where('key', 'student')->value('id')])->save();
        }
        $staff->refresh();
        if ($role === 'class_leader') {
            $this->assertTrue($staff->canUseClassFeatures());
            $this->assertContains($class->id, $staff->accessibleClassIds());
        }
        $this->assertFalse($staff->can('view', $report));
        $this->actingAs($staff, 'sanctum')->getJson('/api/v1/notifications?unread_only=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.unread_count', 1)
            ->assertJsonPath('data.0.id', $accountNotice->id);
        $this->postJson('/api/v1/notifications/'.$notice->id.'/read')->assertForbidden();
        $this->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('data.updated', 1);
        $this->assertNull($notice->fresh()->read_at);
        $this->assertNotNull($accountNotice->fresh()->read_at);
        $this->postJson('/api/v1/notifications/'.$accountNotice->id.'/read')->assertOk();
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.unread_count', 0);
    }

    public function test_lawful_owner_keeps_own_reports_and_notices_without_membership(): void
    {
        $class = Domain::classRoom();
        $leader = Domain::user(UserRole::ClassLeader);
        Domain::leader($leader, $class);
        $owner = Domain::user(UserRole::Student);
        $membership = Domain::member($owner, $class);
        $report = $this->createReport($owner);
        app(ReportWorkflowService::class)->transition($report, ReportStatus::InProgress, $leader);
        $notice = AppNotification::query()->where('user_id', $owner->id)->firstOrFail();
        $this->assertSame('personal', $notice->data['purpose']);
        $membership->forceFill(['status' => 'revoked'])->save();

        $this->actingAs($owner->refresh(), 'sanctum')->getJson('/api/v1/reports/'.$report->public_ref)->assertOk();
        $this->getJson('/api/v1/reports?scope=mine&class_id='.$class->id)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.public_ref', $report->public_ref);
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.reports.total', 1);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.unread_count', 1);
        $this->postJson('/api/v1/notifications/'.$notice->id.'/read')->assertOk();

        $otherClass = Domain::classRoom();
        $this->getJson('/api/v1/reports?scope=mine&class_id='.$otherClass->id)->assertForbidden()
            ->assertJsonPath('error.code', 'CLASS_ACCESS_DENIED');
        $outsider = Domain::user(UserRole::Student);
        Domain::member($outsider, $class);
        $this->actingAs($outsider, 'sanctum')->getJson('/api/v1/reports')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/notifications/'.$notice->id.'/read')->assertForbidden();
    }

    public function test_real_appeal_broadcast_excludes_conflicts_and_new_conflict_hides_it(): void
    {
        $class = Domain::classRoom();
        $leader = Domain::user(UserRole::ClassLeader);
        Domain::leader($leader, $class);
        $reviewer = Domain::user(UserRole::LecturerCoordinator);
        $conflicted = Domain::user(UserRole::LecturerCoordinator);
        Domain::lecturer($reviewer, $class);
        Domain::lecturer($conflicted, $class);
        $owner = Domain::user(UserRole::Student);
        Domain::member($owner, $class);
        $report = $this->createReport($owner);
        app(ReportWorkflowService::class)->transition($report, ReportStatus::Rejected, $leader, ['reason' => 'Synthetic rejection.']);
        $report->conflictsOfInterest()->create(['user_id' => $conflicted->id, 'status' => 'confirmed', 'relationship' => 'synthetic']);
        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/reports/'.$report->public_ref.'/appeals', [
            'reason' => 'PILOT_PRIVATE_APPEAL_CANARY: synthetic independent review request.',
        ])->assertCreated();

        $notice = AppNotification::query()->where('user_id', $reviewer->id)->where('type', 'appeal_submitted')->firstOrFail();
        $this->assertSame('staff', $notice->data['purpose']);
        $this->assertDatabaseMissing('notifications', ['user_id' => $conflicted->id, 'type' => 'appeal_submitted']);
        $this->actingAs($reviewer, 'sanctum')->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.unread_count', 1);
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.appeals.pending', 1);
        $this->actingAs($conflicted, 'sanctum')->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('data.appeals.pending', 0)->assertJsonPath('data.statistics.total_reports', 0);

        $report->conflictsOfInterest()->create(['user_id' => $reviewer->id, 'status' => 'declared', 'relationship' => 'synthetic']);
        $this->actingAs($reviewer, 'sanctum')->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('meta.unread_count', 0);
        $this->postJson('/api/v1/notifications/'.$notice->id.'/read')->assertForbidden();
        $this->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('data.updated', 0);
        $this->assertNull($notice->fresh()->read_at);
        $payloads = AppNotification::query()->get()->toJson();
        foreach (['PILOT_PRIVATE_APPEAL_CANARY', 'PILOT_PRIVATE_TITLE_CANARY', 'PILOT_PRIVATE_BODY_CANARY'] as $canary) {
            $this->assertStringNotContainsString($canary, $payloads);
        }
    }

    #[TestWith(['approve'])]
    #[TestWith(['reject'])]
    public function test_membership_review_notices_are_not_personal_decisions(string $decision): void
    {
        $class = Domain::classRoom();
        $leader = Domain::user(UserRole::ClassLeader);
        $assignment = Domain::leader($leader, $class);
        $applicant = Domain::user(UserRole::Student, ['name' => 'PILOT_APPLICANT_CANARY']);
        $code = ClassInvitationCode::create(['class_id' => $class->id, 'code' => 'PILOTJOIN123', 'created_by' => $leader->id]);
        $membershipId = $this->actingAs($applicant, 'sanctum')->postJson('/api/v1/memberships/join', [
            'invitation_code' => $code->code,
        ])->assertStatus(202)->json('data.id');
        $staffNotice = AppNotification::query()->where('user_id', $leader->id)->firstOrFail();
        $this->assertSame('staff', $staffNotice->data['purpose']);
        $this->assertSame($membershipId, $staffNotice->data['membership_id']);
        $this->actingAs($leader, 'sanctum')->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/memberships/'.$membershipId.'/decide', [
            'decision' => $decision, 'note' => 'PILOT_PRIVATE_DECISION_CANARY',
        ])->assertOk();
        $ownNotice = AppNotification::query()->where('user_id', $applicant->id)->firstOrFail();
        $this->assertSame('personal', $ownNotice->data['purpose']);
        $this->assertSame('membership', $ownNotice->data['resource']);
        $assignment->forceFill(['ends_at' => now()])->save();
        if ($decision === 'approve') {
            ClassMembership::findOrFail($membershipId)->forceFill(['status' => 'revoked'])->save();
        }

        $this->actingAs($leader->refresh(), 'sanctum')->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('meta.unread_count', 0);
        $this->postJson('/api/v1/notifications/'.$staffNotice->id.'/read')->assertForbidden();
        $this->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('data.updated', 0);
        $this->assertNull($staffNotice->fresh()->read_at);
        $response = $this->actingAs($applicant->refresh(), 'sanctum')->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.unread_count', 1)->assertJsonPath('data.0.id', $ownNotice->id);
        $this->assertStringNotContainsString('PILOT_APPLICANT_CANARY', $response->getContent());
        $this->assertStringNotContainsString('PILOT_PRIVATE_DECISION_CANARY', $response->getContent());
        $this->postJson('/api/v1/notifications/'.$ownNotice->id.'/read')->assertOk();
    }

    public function test_legacy_concrete_refs_recheck_authority_and_ambiguous_sensitive_notices_fail_closed(): void
    {
        $class = Domain::classRoom();
        $owner = Domain::user(UserRole::Student);
        $membership = Domain::member($owner, $class);
        $report = $this->createReport($owner);
        $membership->forceFill(['status' => 'revoked'])->save();
        $personal = AppNotification::create(['user_id' => $owner->id, 'type' => 'report_status_changed',
            'title' => 'Legacy owner notice', 'data' => ['report_ref' => $report->public_ref]]);
        $account = AppNotification::create(['user_id' => $owner->id, 'type' => 'security_notice',
            'title' => 'Legacy account notice', 'data' => []]);
        $identity = AppNotification::create(['user_id' => $owner->id, 'type' => 'identity_verification',
            'title' => 'Legacy personal identity notice', 'data' => []]);
        $hidden = [];
        foreach ([
            ['type' => 'report_status_changed', 'data' => []],
            ['type' => 'security_notice', 'data' => ['class_id' => $class->id]],
            ['type' => 'report_status_changed', 'data' => ['report_ref' => 'PGD-NOTEXIST00']],
            ['type' => 'membership_decision', 'data' => ['membership_id' => 99999999]],
            ['type' => 'report_status_changed', 'data' => ['resource' => 'account', 'purpose' => 'personal', 'report_ref' => $report->public_ref]],
        ] as $attributes) {
            $hidden[] = AppNotification::create(array_merge(['user_id' => $owner->id, 'title' => 'Ambiguous legacy notice'], $attributes));
        }

        $this->actingAs($owner->refresh(), 'sanctum')->getJson('/api/v1/notifications?per_page=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.pagination.total', 3)->assertJsonPath('meta.unread_count', 3);
        $this->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('data.updated', 3);
        foreach ($hidden as $notice) {
            $this->assertFalse($owner->can('view', $notice));
            $this->assertNull($notice->fresh()->read_at);
        }
        foreach ([$personal, $account, $identity] as $notice) {
            $this->assertNotNull($notice->fresh()->read_at);
        }
        $this->assertSame(['report_ref' => $report->public_ref], $personal->fresh()->data);
        $this->assertDatabaseCount('notifications', 8);
    }

    public function test_legacy_staff_report_and_membership_references_do_not_inherit_student_access(): void
    {
        $class = Domain::classRoom();
        $leader = Domain::user(UserRole::ClassLeader);
        $assignment = Domain::leader($leader, $class);
        $owner = Domain::user(UserRole::Student);
        $membership = Domain::member($owner, $class);
        $report = $this->createReport($owner);
        $legacyReport = AppNotification::create(['user_id' => $leader->id, 'type' => 'report_status_changed',
            'title' => 'Legacy staff report notice', 'data' => ['report_ref' => $report->public_ref]]);
        $legacyReview = AppNotification::create(['user_id' => $leader->id, 'type' => 'membership_decision',
            'title' => 'Legacy review notice', 'data' => ['membership_id' => $membership->id]]);
        $this->assertTrue($leader->can('view', $legacyReport));
        $this->assertTrue($leader->can('view', $legacyReview));
        $assignment->forceFill(['ends_at' => now()])->save();
        $this->assertTrue($leader->canUseClassFeatures());
        $this->assertFalse($leader->can('view', $legacyReport));
        $this->assertFalse($leader->can('view', $legacyReview));
        $this->actingAs($leader, 'sanctum')->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('meta.unread_count', 0);
        $this->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('data.updated', 0);
    }
}
