<?php

use App\Enums\IdentityStatus;
use App\Enums\UserRole;
use App\Models\IdentityVerification;
use App\Models\User;
use App\Services\IdentityVerificationService;
use App\Services\UserAccessLock;
use App\Services\UserAdministrationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\Support\Domain;
use Tests\Support\TestIsolation;

require dirname(__DIR__).'/bootstrap.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
TestIsolation::verify($app);

$waitFor = function (string $file): void {
    $deadline = microtime(true) + 10;
    while (! is_file($file)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Test coordination barrier expired.');
        }
        usleep(10000);
        clearstatcache(true, $file);
    }
};

if (($argv[1] ?? '') === 'worker') {
    [$script, $mode, $action, $adminId, $reviewerId, $candidateId, $barrier] = $argv;
    $base = realpath(storage_path('framework/cache'));
    if ($base === false || dirname($barrier) !== $base) {
        throw new RuntimeException('Unsafe coordination path.');
    }
    DB::transaction(function () use ($action, $adminId, $reviewerId, $candidateId, $barrier, $waitFor): void {
        app(UserAccessLock::class)->lock();
        if ($action === 'suspend') {
            app(UserAdministrationService::class)->suspend(User::findOrFail($reviewerId), User::findOrFail($adminId), 'Fictional concurrency test.');
        } else {
            app(IdentityVerificationService::class)->approve(IdentityVerification::findOrFail($candidateId), User::findOrFail($reviewerId));
        }
        file_put_contents($barrier.'.locked', 'ready');
        $waitFor($barrier.'.attempting');
    });
    echo json_encode(['committed' => true, 'connection_id' => DB::selectOne('SELECT CONNECTION_ID() AS id')->id]);
    exit;
}

if (User::query()->exists()) {
    throw new RuntimeException('Concurrency test refuses a populated test schema.');
}
$process = null;
$files = [];
$results = [];
$paths = [];
try {
    Domain::seedCatalog();
    foreach (['suspend', 'approve'] as $action) {
        $admin = Domain::user(UserRole::SuperAdmin);
        $reviewer = Domain::user(UserRole::IdentityVerifier);
        $student = Domain::user(UserRole::Student, ['identity_status' => IdentityStatus::Unverified]);
        $candidate = app(IdentityVerificationService::class)->submit($student,
            UploadedFile::fake()->image('synthetic-race.png'), 'SYNTHETIC-NIM', 'Synthetic Candidate');
        $paths[] = $candidate->ktm_path;
        $barrier = storage_path('framework/cache/pilot-race-'.bin2hex(random_bytes(6)));
        $files = array_merge($files, [$barrier.'.locked', $barrier.'.attempting']);
        $process = new Process([PHP_BINARY, __FILE__, 'worker', $action, (string) $admin->id,
            (string) $reviewer->id, (string) $candidate->id, $barrier], base_path(), null, null, 20);
        $process->start();
        $waitFor($barrier.'.locked');
        $gate = (object) ['armed' => true];
        DB::connection()->beforeExecuting(function ($sql) use ($gate, $barrier): void {
            if ($gate->armed && str_contains($sql, '`roles`') && str_contains(strtolower($sql), 'for update')) {
                $gate->armed = false;
                file_put_contents($barrier.'.attempting', 'ready');
            }
        });
        $denied = false;
        try {
            if ($action === 'suspend') {
                app(IdentityVerificationService::class)->approve($candidate, $reviewer);
            } else {
                app(IdentityVerificationService::class)->reject($candidate, $reviewer, 'Synthetic competing rejection.');
            }
        } catch (AuthorizationException|ValidationException) {
            $denied = true;
        }
        $process->wait();
        $worker = json_decode($process->getOutput(), true);
        $expected = $action === 'suspend' ? IdentityStatus::PendingVerification : IdentityStatus::Verified;
        if (! $process->isSuccessful() || ! $denied || $candidate->fresh()->status !== $expected
            || ! ($worker['committed'] ?? false)
            || $worker['connection_id'] === DB::selectOne('SELECT CONNECTION_ID() AS id')->id) {
            throw new RuntimeException('Concurrent decision invariant failed.');
        }
        $results[] = ['first_commit' => $action, 'competing_identity_action' => 'denied', 'separate_connections' => true, 'status' => $expected->value];
    }
    echo json_encode(['concurrency' => 'PASS', 'cases' => $results], JSON_PRETTY_PRINT).PHP_EOL;
} finally {
    if ($process?->isRunning()) {
        $process->stop();
    }
    foreach ($paths as $path) {
        Storage::disk('private')->delete($path);
    }
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    TestIsolation::verify($app);
    // All records above were created by this test, on the verified empty test schema.
    Artisan::call('migrate:fresh', ['--force' => true]);
    echo "Isolated synthetic test records cleared; main/demo were not connected.\n";
}
