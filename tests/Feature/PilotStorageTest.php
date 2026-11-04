<?php

namespace Tests\Feature;

use App\Enums\AttachmentType;
use App\Enums\UserRole;
use App\Models\Report;
use App\Services\PrivateStorageService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('reportUploadLimits')]
    public function test_report_categories_advertise_upload_limits(?int $configuredBytes, int $expectedBytes): void
    {
        if ($configuredBytes !== null) {
            config(['security.uploads.report_max_total_bytes' => $configuredBytes]);
        }

        $this->actingAs(Domain::user(UserRole::Student), 'sanctum')
            ->getJson('/api/v1/report-categories')->assertOk()
            ->assertJsonPath('meta.uploads', [
                'max_files' => 5,
                'max_file_bytes' => 8388608,
                'max_total_bytes' => $expectedBytes,
            ]);
    }

    public static function reportUploadLimits(): array
    {
        return [
            'safe default' => [null, 26214400],
            'configured limit' => [16777216, 16777216],
        ];
    }

    #[DataProvider('oversizedReportAttachments')]
    public function test_oversized_report_attachments_return_422_without_persisting(array $sizes, int $limit, string $errorKey): void
    {
        Storage::fake('private');
        config(['security.uploads.report_max_total_bytes' => $limit]);
        $student = Domain::user(UserRole::Student);
        Domain::member($student, Domain::classRoom());

        $response = $this->actingAs($student, 'sanctum')->postJson('/api/v1/reports', [
            'category_id' => Domain::category('facilities')->id,
            'title' => 'Lampiran pengaduan fiktif',
            'description' => 'Pengujian fiktif batas lampiran tanpa data pribadi atau dokumen nyata.',
            'attachments' => $this->reportImages($sizes),
        ])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors($errorKey, 'error.details');

        if (array_sum($sizes) > $limit) {
            $maxMiB = $limit / 1048576;
            $response->assertJsonPath('error.details.attachments', ["Ukuran total lampiran maksimal {$maxMiB} MiB."]);
        }
        $this->assertDatabaseCount('reports', 0);
        $this->assertDatabaseCount('report_private_details', 0);
        $this->assertDatabaseCount('attachments', 0);
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public static function oversizedReportAttachments(): array
    {
        return [
            'one byte over aggregate' => [[8388608, 8388608, 8388608, 1048577], 26214400, 'attachments'],
            'four valid 7 MiB images' => [[7340032, 7340032, 7340032, 7340032], 26214400, 'attachments'],
            'three images over configured limit' => [[6291456, 6291456, 6291456], 16777216, 'attachments'],
            'per-file limit preserved' => [[8388609], 26214400, 'attachments.0'],
            'file count preserved' => [[1024, 1024, 1024, 1024, 1024, 1024], 26214400, 'attachments'],
        ];
    }

    #[DataProvider('allowedReportAttachments')]
    public function test_report_attachments_at_or_below_total_limit_are_stored(array $sizes): void
    {
        Storage::fake('private');
        $student = Domain::user(UserRole::Student);
        Domain::member($student, Domain::classRoom());

        $response = $this->actingAs($student, 'sanctum')->postJson('/api/v1/reports', [
            'category_id' => Domain::category('facilities')->id,
            'title' => 'Lampiran pengaduan fiktif',
            'description' => 'Pengujian fiktif batas lampiran tanpa data pribadi atau dokumen nyata.',
            'attachments' => $this->reportImages($sizes),
        ])->assertCreated();

        $report = Report::where('public_ref', $response->json('data.public_ref'))->firstOrFail();
        $this->assertSame($student->id, $report->reporter_id);
        $this->assertDatabaseCount('reports', 1);
        $this->assertCount(count($sizes), $report->attachments);
        $this->assertSame(array_sum($sizes), $report->attachments->sum('size'));
        Storage::disk('private')->assertExists($report->attachments->pluck('path')->all());
    }

    public static function allowedReportAttachments(): array
    {
        return [
            'exactly 25 MiB' => [[8388608, 8388608, 8388608, 1048576]],
            'three 8 MiB files remain allowed' => [[8388608, 8388608, 8388608]],
            'five small files remain allowed' => [[1024, 1024, 1024, 1024, 1024]],
        ];
    }

    private function reportImages(array $sizes): array
    {
        return array_map(function (int $size): UploadedFile {
            $file = UploadedFile::fake()->image('synthetic.png');
            $file->sizeToReport = $size;

            return $file;
        }, $sizes);
    }

    public function test_post_larger_than_php_limit_uses_safe_413_envelope(): void
    {
        $this->withServerVariables(['CONTENT_LENGTH' => (string) PHP_INT_MAX])
            ->postJson('/api/v1/reports', [])
            ->assertStatus(413)
            ->assertExactJson([
                'message' => 'Permintaan tidak dapat diproses.',
                'error' => ['code' => 'HTTP_ERROR'],
            ]);

        $this->assertDatabaseCount('reports', 0);
        $this->assertDatabaseCount('attachments', 0);
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
