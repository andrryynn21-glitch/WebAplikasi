<?php

namespace Tests\Feature;

use App\Enums\IdentityStatus;
use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Models\ClassInvitationCode;
use App\Models\ClassMembership;
use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Domain;
use Tests\TestCase;

/**
 * Skenario wajib #1, #2, #6, #7, #8:
 * isolasi laporan, isolasi kelas, privilege escalation, akun belum
 * terverifikasi, dan kode undangan bukan pengganti verifikasi.
 */
class AuthorizationIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Domain::seedCatalog();
    }

    private function makeReport($reporter, $class): Report
    {
        $this->actingAs($reporter, 'sanctum');

        $response = $this->postJson('/api/v1/reports', [
            'category_id' => Domain::category('academic')->id,
            'title' => 'Nilai mata kuliah tidak sesuai',
            'description' => 'Nilai saya pada mata kuliah ini tidak sesuai dengan komponen penilaian yang dijanjikan.',
        ])->assertCreated();

        return Report::query()->where('public_ref', $response->json('data.public_ref'))->firstOrFail();
    }

    /** #1 Mahasiswa A tidak bisa membaca laporan mahasiswa B. */
    public function test_student_cannot_read_another_students_report(): void
    {
        $class = Domain::classRoom();
        $a = Domain::user(UserRole::Student, ['nim' => 'A1']);
        $b = Domain::user(UserRole::Student, ['nim' => 'B1']);
        Domain::member($a, $class);
        Domain::member($b, $class);

        $report = $this->makeReport($a, $class);

        $this->actingAs($b, 'sanctum')
            ->getJson('/api/v1/reports/'.$report->public_ref)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'REPORT_ACCESS_DENIED');

        $this->actingAs($b, 'sanctum')
            ->getJson('/api/v1/reports')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /** #2 Mahasiswa tidak bisa mengakses kelas lain. */
    public function test_student_cannot_access_another_class_data(): void
    {
        $classA = Domain::classRoom(['code' => 'A']);
        $classB = Domain::classRoom(['code' => 'B']);

        $student = Domain::user(UserRole::Student, ['nim' => 'S1']);
        $other = Domain::user(UserRole::Student, ['nim' => 'S2']);

        Domain::member($student, $classA);
        Domain::member($other, $classB);

        $otherReport = $this->makeReport($other, $classB);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/reports?class_id='.$classB->id)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'CLASS_ACCESS_DENIED');

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/reports/'.$otherReport->public_ref)
            ->assertForbidden();
    }

    /** #6 Pengguna tidak bisa meningkatkan role sendiri. */
    public function test_user_cannot_escalate_own_role_via_registration_or_admin_endpoint(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Pendaftar Nakal',
            'email' => 'nakal@example.test',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'nim' => '999999',
            'role' => 'super_admin',
            'identity_status' => 'verified',
            'is_active' => true,
        ])->assertCreated()
            ->assertJsonPath('data.user.role', 'student')
            ->assertJsonPath('data.user.identity_status', 'unverified');
    }

    public function test_super_admin_cannot_assign_role_to_self(): void
    {
        $admin = Domain::user(UserRole::SuperAdmin);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/users/'.$admin->id.'/role', [
                'role' => 'lecturer_coordinator',
                'reason' => 'Mencoba menaikkan role sendiri untuk uji.',
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'SELF_ROLE_CHANGE_DENIED');
    }

    /** #7 Akun belum terverifikasi tidak bisa memakai fitur kelas. */
    public function test_unverified_account_cannot_use_class_features(): void
    {
        $class = Domain::classRoom();
        $user = Domain::user(UserRole::Student, [
            'nim' => 'U1',
            'identity_status' => IdentityStatus::PendingVerification,
        ]);

        // Keanggotaan aktif pun tidak membuka fitur bila identitas belum verified.
        ClassMembership::create([
            'user_id' => $user->id,
            'class_id' => $class->id,
            'status' => 'active',
            'approved_at' => now(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/reports', [
                'category_id' => Domain::category('academic')->id,
                'title' => 'Percobaan dari akun belum verifikasi',
                'description' => 'Deskripsi panjang untuk melewati validasi minimal tiga puluh karakter.',
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'IDENTITY_NOT_VERIFIED');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/aspirations')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'IDENTITY_NOT_VERIFIED');
    }

    /** #8 Kode undangan tidak menggantikan verifikasi identitas. */
    public function test_invitation_code_does_not_replace_identity_verification(): void
    {
        $class = Domain::classRoom();
        $user = Domain::user(UserRole::Student, [
            'nim' => 'INV1',
            'identity_status' => IdentityStatus::PendingVerification,
        ]);

        $code = ClassInvitationCode::create([
            'class_id' => $class->id,
            'code' => 'KODE-VALID-1',
            'max_uses' => 10,
            'created_by' => null,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/memberships/join', ['invitation_code' => $code->code])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertDatabaseMissing('class_memberships', [
            'user_id' => $user->id,
            'class_id' => $class->id,
        ]);

        // Setelah identitas terverifikasi, kode yang sama hanya menghasilkan pending.
        $user->forceFill(['identity_status' => IdentityStatus::Verified])->save();

        $this->actingAs($user->refresh(), 'sanctum')
            ->postJson('/api/v1/memberships/join', ['invitation_code' => $code->code])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('class_memberships', [
            'user_id' => $user->id,
            'class_id' => $class->id,
            'status' => 'pending',
        ]);

        // Selama masih pending, fitur kelas belum terbuka.
        $this->actingAs($user->refresh(), 'sanctum')
            ->getJson('/api/v1/aspirations')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'MEMBERSHIP_NOT_ACTIVE');
    }

    /** #3 Ketua kelas tidak bisa membaca laporan privat tanpa penugasan. */
    public function test_class_leader_cannot_read_private_report_without_assignment(): void
    {
        $class = Domain::classRoom();
        $leader = Domain::user(UserRole::ClassLeader, ['nim' => 'L1']);
        $reporter = Domain::user(UserRole::Student, ['nim' => 'R1']);

        Domain::leader($leader, $class);
        Domain::member($reporter, $class);

        // Kategori sensitif -> routing manual, tidak ditugaskan ke ketua kelas.
        $this->actingAs($reporter, 'sanctum');
        $response = $this->postJson('/api/v1/reports', [
            'category_id' => Domain::category('lecturer_conduct')->id,
            'title' => 'Perilaku dosen di kelas',
            'description' => 'Dosen sering membatalkan kelas tanpa pemberitahuan dan tidak menggantinya.',
        ])->assertCreated();

        $report = Report::query()->where('public_ref', $response->json('data.public_ref'))->firstOrFail();
        $this->assertSame(ReportStatus::NeedsReview, $report->status);

        // Ketua kelas dapat melihat metadata kelas...
        $this->actingAs($leader, 'sanctum')
            ->getJson('/api/v1/reports/'.$report->public_ref)
            ->assertOk()
            ->assertJsonPath('data.private_details.description', null);

        // ...tetapi tidak bisa membuka isi privat tanpa penugasan.
        $this->assertFalse($leader->can('viewPrivateDetails', $report));
        $this->assertFalse($leader->can('updateStatus', $report));
    }
}
