<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AppNotification;
use App\Models\Aspiration;
use App\Models\AuditLog;
use App\Models\IdentityVerification;
use App\Models\SecurityEvent;
use App\Services\VoiceWarningManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Domain;
use Tests\TestCase;

/**
 * Skenario wajib #12, #13, #14, #15, #16.
 */
class SecurityAspirationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Domain::seedCatalog();
    }

    private function studentInClass(): array
    {
        $class = Domain::classRoom();
        $student = Domain::user(UserRole::Student, ['nim' => 'S1']);
        Domain::member($student, $class);

        return [$student, $class];
    }

    /** #12 Upvote duplikat dicegah. */
    public function test_duplicate_upvote_is_prevented(): void
    {
        [$student, $class] = $this->studentInClass();

        $aspiration = Aspiration::create([
            'class_id' => $class->id,
            'author_id' => $student->id,
            'title' => 'Usul jam konsultasi tambahan',
            'body' => 'Mohon diadakan jam konsultasi tambahan menjelang ujian akhir.',
        ]);

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/v1/aspirations/'.$aspiration->id.'/support')
            ->assertOk()
            ->assertJsonPath('data.created', true)
            ->assertJsonPath('data.supports_count', 1);

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/v1/aspirations/'.$aspiration->id.'/support')
            ->assertOk()
            ->assertJsonPath('data.created', false)
            ->assertJsonPath('data.supports_count', 1);

        $this->assertDatabaseCount('aspiration_supports', 1);
    }

    /** #13 File privat tidak dapat diakses tanpa otorisasi. */
    public function test_private_ktm_is_not_accessible_without_authorization(): void
    {
        Storage::fake('private');

        $owner = Domain::user(UserRole::Student, ['nim' => 'OWN']);
        $stranger = Domain::user(UserRole::Student, ['nim' => 'STR']);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/identity/submissions', [
                'ktm' => UploadedFile::fake()->image('ktm.jpg'),
                'nim' => 'OWN',
                'full_name' => 'Pemilik KTM',
            ])
            ->assertCreated();

        $verification = IdentityVerification::query()->firstOrFail();

        // Pemilik dapat mengunduh.
        $this->actingAs($owner, 'sanctum')
            ->get('/api/v1/identity-verifications/'.$verification->id.'/ktm')
            ->assertOk();

        // Pengguna lain tidak.
        $this->actingAs($stranger, 'sanctum')
            ->getJson('/api/v1/identity-verifications/'.$verification->id.'/ktm')
            ->assertForbidden();

        // Super Admin pun tidak otomatis.
        $admin = Domain::user(UserRole::SuperAdmin);
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/identity-verifications/'.$verification->id.'/ktm')
            ->assertForbidden();

        // File benar-benar berada di disk privat, bukan public.
        Storage::disk('private')->assertExists($verification->ktm_path);
        Storage::disk('public')->assertMissing($verification->ktm_path);
    }

    /** #14 Notifikasi dan log tidak membocorkan data rahasia. */
    public function test_notifications_and_logs_never_contain_private_report_content(): void
    {
        $class = Domain::classRoom();
        $leader = Domain::user(UserRole::ClassLeader, ['nim' => 'L1']);
        Domain::leader($leader, $class);
        $reporter = Domain::user(UserRole::Student, ['nim' => 'R1']);
        Domain::member($reporter, $class);

        $secret = 'RAHASIA-INTERNAL-9f8e7d6c';

        $this->actingAs($reporter, 'sanctum');
        $response = $this->postJson('/api/v1/reports', [
            'category_id' => Domain::category('interpersonal')->id,
            'title' => 'Konflik antar teman sekelas',
            'description' => 'Situasi kelas terganggu karena konflik pribadi. Penanda '.$secret.' tidak boleh bocor.',
            'involved_parties' => [$secret],
        ])->assertCreated();

        $ref = $response->json('data.public_ref');

        // Notifikasi hanya memuat referensi, bukan isi.
        $notifications = AppNotification::query()->get();
        $this->assertNotEmpty($notifications);

        foreach ($notifications as $notification) {
            $payload = json_encode([
                $notification->title,
                $notification->message,
                $notification->data,
            ]);

            $this->assertStringNotContainsString($secret, $payload);
            $this->assertStringNotContainsString('konflik pribadi', strtolower($payload));
        }

        // Security event juga tidak memuat isi laporan.
        foreach (SecurityEvent::query()->get() as $event) {
            $payload = json_encode([$event->denial_reason, $event->context]);
            $this->assertStringNotContainsString($secret, $payload);
        }

        // Audit log hanya metadata.
        foreach (AuditLog::query()->get() as $log) {
            $payload = json_encode($log->changes);
            $this->assertStringNotContainsString($secret, $payload);
        }

        $this->assertStringStartsWith('PGD-', $ref);
    }

    /** #15 Rate limiting bekerja. */
    public function test_rate_limiting_blocks_repeated_login_attempts(): void
    {
        Domain::user(UserRole::Student, ['email' => 'target@example.test']);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'target@example.test',
                'password' => 'salah-terus',
            ]);
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'target@example.test',
            'password' => 'salah-terus',
        ])->assertStatus(429);
    }

    public function test_comment_rate_limit_is_enforced(): void
    {
        [$student, $class] = $this->studentInClass();

        $aspiration = Aspiration::create([
            'class_id' => $class->id,
            'author_id' => $student->id,
            'title' => 'Diskusi jadwal kelas',
            'body' => 'Kita perlu menyepakati ulang jadwal kelas pengganti.',
        ]);

        for ($i = 0; $i < 20; $i++) {
            $this->actingAs($student, 'sanctum')
                ->postJson('/api/v1/aspirations/'.$aspiration->id.'/comments', [
                    'body' => 'Komentar ke-'.$i,
                ]);
        }

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/v1/aspirations/'.$aspiration->id.'/comments', [
                'body' => 'Komentar melebihi batas',
            ])
            ->assertStatus(429);
    }

    /** #16 Kegagalan audio tidak mengubah keputusan akses. */
    public function test_voice_warning_never_affects_access_decision(): void
    {
        [$student, $class] = $this->studentInClass();
        $outsider = Domain::user(UserRole::Student, ['nim' => 'OUT']);

        // Suara diaktifkan: akses tetap ditolak.
        config()->set('security.voice_warnings.enabled', true);

        $this->actingAs($outsider, 'sanctum')
            ->getJson('/api/v1/reports/1')
            ->assertStatus(404);

        $this->actingAs($outsider, 'sanctum')
            ->postJson('/api/v1/aspirations', [
                'title' => 'Percobaan tanpa kelas',
                'body' => 'Percobaan tanpa keanggotaan kelas aktif.',
            ])
            ->assertForbidden();

        // Suara dimatikan: keputusan identik.
        config()->set('security.voice_warnings.enabled', false);

        $this->actingAs($outsider, 'sanctum')
            ->postJson('/api/v1/aspirations', [
                'title' => 'Percobaan tanpa kelas',
                'body' => 'Percobaan tanpa keanggotaan kelas aktif.',
            ])
            ->assertForbidden();

        // VoiceWarningManager tidak pernah melempar exception.
        $manager = app(VoiceWarningManager::class);

        $event = SecurityEvent::create([
            'user_id' => $outsider->id,
            'category' => 'repeated_denials',
            'severity' => 'critical',
        ]);

        config()->set('security.voice_warnings.enabled', true);
        $instruction = $manager->forEvent($event);

        $this->assertTrue($instruction['play']);
        $this->assertTrue($instruction['placeholder']);
        $this->assertNull($instruction['sound']);
    }

    /** #14 lanjutan: notifikasi aspirasi juga tidak membawa identitas pelapor. */
    public function test_aspiration_notifications_do_not_leak_nim(): void
    {
        [$author, $class] = $this->studentInClass();
        $commenter = Domain::user(UserRole::Student, ['nim' => 'SECRET-NIM']);
        Domain::member($commenter, $class);

        $aspiration = Aspiration::create([
            'class_id' => $class->id,
            'author_id' => $author->id,
            'title' => 'Usul kebersihan kelas',
            'body' => 'Mohon jadwal piket diperjelas.',
        ]);

        $this->actingAs($commenter, 'sanctum')
            ->postJson('/api/v1/aspirations/'.$aspiration->id.'/comments', [
                'body' => 'Setuju, perlu diperjelas.',
            ])
            ->assertCreated();

        foreach (AppNotification::query()->get() as $notification) {
            $this->assertStringNotContainsString('SECRET-NIM', json_encode($notification->toArray()));
        }
    }
}
