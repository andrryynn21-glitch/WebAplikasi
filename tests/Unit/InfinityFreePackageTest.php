<?php

namespace Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use ZipArchive;

class InfinityFreePackageTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/ruang-dengar-package-'.bin2hex(random_bytes(8));
        foreach (['app', 'bootstrap/cache', 'config', 'database', 'public/build', 'resources', 'routes', 'storage/app/private', 'vendor'] as $directory) {
            mkdir($this->directory.'/source/'.$directory, 0700, true);
        }
        foreach (['artisan', 'composer.json', 'composer.lock', 'bootstrap/app.php', 'public/index.php', 'public/.htaccess', 'public/build/manifest.json', 'vendor/autoload.php'] as $file) {
            file_put_contents($this->directory.'/source/'.$file, 'synthetic-runtime-fixture');
        }
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function package(): Process
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/.github/package-infinityfree.php', $this->directory.'/source', $this->directory.'/release.zip']);
        $process->run();

        return $process;
    }

    public function test_clean_runtime_is_packaged_with_private_directory_guards_and_a_checksum(): void
    {
        $process = $this->package();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($this->directory.'/release.zip', ZipArchive::CHECKCONS));
        $this->assertStringContainsString('RewriteEngine On', $zip->getFromName('.htaccess'));
        foreach (['app', 'bootstrap', 'config', 'database', 'resources', 'routes', 'storage', 'vendor'] as $directory) {
            $this->assertSame("Require all denied\n", $zip->getFromName($directory.'/.htaccess'));
        }
        $this->assertSame('synthetic-runtime-fixture', $zip->getFromName('public/.htaccess'));
        $this->assertSame('synthetic-runtime-fixture', $zip->getFromName('public/build/manifest.json'));
        $this->assertStringContainsString("APP_DEBUG=false\n", $zip->getFromName('.env.production.example'));
        $this->assertFalse($zip->locateName('.env'));
        $zip->close();
        $this->assertSame(hash_file('sha256', $this->directory.'/release.zip').'  release.zip'."\n", file_get_contents($this->directory.'/release.zip.sha256'));
    }

    #[DataProvider('forbiddenPaths')]
    public function test_private_files_runtime_data_and_oversized_files_are_refused(string $path, int $size): void
    {
        file_put_contents($this->directory.'/source/'.$path, str_repeat('x', $size));
        $process = $this->package();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString($path, $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->directory.'/release.zip');
        $this->assertFileDoesNotExist($this->directory.'/release.zip.sha256');
    }

    public static function forbiddenPaths(): array
    {
        return [
            'environment secret' => ['.env', 10],
            'private upload' => ['storage/app/private/identity.jpg', 10],
            'cached credentials' => ['bootstrap/cache/config.php', 10],
            'development server' => ['public/hot', 10],
            'database dump' => ['database/live.sql', 10],
            'oversized PHP' => ['public/large.php', 1000001],
            'oversized JavaScript' => ['public/large.js', 1000001],
            'oversized htaccess' => ['public/.htaccess', 10001],
        ];
    }

    public function test_symlinks_and_missing_runtime_files_are_refused(): void
    {
        symlink($this->directory.'/source/app', $this->directory.'/source/public/storage');
        $this->assertFalse($this->package()->isSuccessful());
        $this->assertFileDoesNotExist($this->directory.'/release.zip');
        unlink($this->directory.'/source/public/storage');
        unlink($this->directory.'/source/public/build/manifest.json');
        $this->assertFalse($this->package()->isSuccessful());
        $this->assertFileDoesNotExist($this->directory.'/release.zip');
    }

    public function test_existing_archive_is_never_overwritten(): void
    {
        file_put_contents($this->directory.'/release.zip', 'existing-release');
        $this->assertFalse($this->package()->isSuccessful());
        $this->assertSame('existing-release', file_get_contents($this->directory.'/release.zip'));
    }
}
