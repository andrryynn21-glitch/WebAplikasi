<?php

namespace Tests\Feature;

use App\Enums\AttachmentType;
use App\Enums\UserRole;
use App\Services\PrivateStorageService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Domain;
use Tests\TestCase;

class PilotStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Domain::seedCatalog();
    }

    public function test_default_private_disk_and_log_are_isolated_from_application_storage(): void
    {
        $this->assertStringContainsString('/storage/framework/pilot-testing/', Storage::disk('private')->path('probe'));
        $this->assertStringContainsString('/storage/framework/pilot-testing/', config('logging.channels.single.path'));
        $this->assertFalse(config('filesystems.disks.private.serve'));
    }

    public function test_filename_is_normalized_without_header_control_or_client_path(): void
    {
        $user = Domain::user(UserRole::Student);
        $original = UploadedFile::fake()->image('ktm.png');
        $file = new UploadedFile($original->getRealPath(), "../folder/ktm\r\nInjected.png", 'image/png', null, true);
        $verification = app(PrivateStorageService::class)->storeIdentityDocument($user, $file);
        try {
            $this->assertSame('ktmInjected.png', $verification->ktm_original_name);
            $this->assertSame(hash_file('sha256', $file->getRealPath()), $verification->ktm_checksum);
            $this->assertTrue(Storage::disk('private')->exists($verification->ktm_path));
        } finally {
            Storage::disk('private')->delete($verification->ktm_path);
        }
    }

    public function test_empty_upload_and_mime_spoof_do_not_create_records(): void
    {
        $user = Domain::user(UserRole::Student);
        foreach ([UploadedFile::fake()->createWithContent('empty.pdf', ''),
            UploadedFile::fake()->createWithContent('fake.png', '<?php not an image'),
            UploadedFile::fake()->createWithContent('truncated.pdf', '%PDF-1.4 incomplete')] as $file) {
            try {
                app(PrivateStorageService::class)->storeIdentityDocument($user, $file);
                $this->fail('Malformed upload must be rejected.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('identity_verifications', 0);
            }
        }
    }

    public function test_store_failure_does_not_create_attachment_metadata(): void
    {
        $user = Domain::user(UserRole::Student);
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFileAs')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('private')->once()->andReturn($disk);

        try {
            app(PrivateStorageService::class)->storeAttachment(
                UploadedFile::fake()->createWithContent('evidence.txt', 'Fictional evidence only.'),
                $user, $user, AttachmentType::ReportAttachment,
            );
            $this->fail('Failed storage cannot yield metadata.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('attachments', 0);
        }
    }

    public function test_download_rejects_traversal_and_symlink_outside_private_root(): void
    {
        $public = Storage::disk('public');
        $private = Storage::disk('private');
        $public->put('pilot-canary.txt', 'Fictional public-root canary.');
        symlink($public->path('pilot-canary.txt'), $private->path('pilot-link.txt'));
        try {
            foreach (['../public/pilot-canary.txt', '/etc/passwd', 'pilot-link.txt'] as $path) {
                try {
                    app(PrivateStorageService::class)->download($path, 'file.txt', 'text/plain');
                    $this->fail('Unsafe file location should not be streamed.');
                } catch (HttpException $e) {
                    $this->assertSame(404, $e->getStatusCode());
                }
            }
        } finally {
            unlink($private->path('pilot-link.txt'));
            $public->delete('pilot-canary.txt');
        }
    }
}
