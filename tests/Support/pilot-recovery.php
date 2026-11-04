<?php

use App\Enums\IdentityStatus;
use App\Enums\UserRole;
use App\Models\IdentityVerification;
use App\Models\User;
use App\Services\IdentityVerificationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\Support\Domain;
use Tests\Support\TestIsolation;

require dirname(__DIR__).'/bootstrap.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
TestIsolation::verify($app);
if (User::query()->exists()) {
    throw new RuntimeException('Recovery simulation requires an empty test schema.');
}
$config = config('database.connections.mysql');
$mysqlOptions = ['--host=127.0.0.1', '--port=3306', '--user='.$config['username']];
$processEnv = ['MYSQL_PWD' => $config['password']];
$backup = storage_path('framework/cache/pilot-recovery-'.bin2hex(random_bytes(8)).'.encrypted');
$path = null;
try {
    Domain::seedCatalog();
    $student = Domain::user(UserRole::Student, ['identity_status' => IdentityStatus::Unverified]);
    $candidate = app(IdentityVerificationService::class)->submit($student,
        UploadedFile::fake()->image('synthetic-recovery.png'), 'RECOVERY-CANDIDATE', 'Synthetic Recovery');
    $path = $candidate->ktm_path;
    $checksum = $candidate->ktm_checksum;
    $dbRows = ['users' => User::count(), 'identity' => IdentityVerification::count()];
    $dump = new Process(array_merge(['mysqldump', '--single-transaction', '--no-tablespaces', '--skip-comments',
        '--set-gtid-purged=OFF', '--skip-add-locks'], $mysqlOptions, ['pengaduan_unpam_test']), base_path(), $processEnv, null, 30);
    $dump->run();
    if (! $dump->isSuccessful()) {
        throw new RuntimeException('Test dump failed; diagnostic output intentionally withheld.');
    }
    $bundle = json_encode(['sql' => $dump->getOutput(), 'file' => base64_encode(Storage::disk('private')->get($path))], JSON_THROW_ON_ERROR);
    $key = random_bytes(32);
    $nonce = random_bytes(12);
    $ciphertext = openssl_encrypt($bundle, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, 'pilot-recovery-test');
    if ($ciphertext === false) {
        throw new RuntimeException('Test backup encryption failed.');
    }
    file_put_contents($backup, $ciphertext);
    chmod($backup, 0600);
    unset($bundle);

    // Simulate loss only after the complete encrypted synthetic backup exists.
    TestIsolation::verify($app);
    Artisan::call('migrate:fresh', ['--force' => true]);
    Storage::disk('private')->delete($path);
    $restored = openssl_decrypt(file_get_contents($backup), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, 'pilot-recovery-test');
    if ($restored === false) {
        throw new RuntimeException('Test restore authentication failed.');
    }
    $bundle = json_decode($restored, true, flags: JSON_THROW_ON_ERROR);
    TestIsolation::verify($app);
    $restore = new Process(array_merge(['mysql'], $mysqlOptions, ['--database=pengaduan_unpam_test']), base_path(), $processEnv, $bundle['sql'], 30);
    $restore->run();
    if (! $restore->isSuccessful()) {
        throw new RuntimeException('Test restore failed; output intentionally withheld.');
    }
    Storage::disk('private')->put($path, base64_decode($bundle['file'], true));
    $record = IdentityVerification::findOrFail($candidate->id);
    if ($record->ktm_checksum !== $checksum || hash_file('sha256', Storage::disk('private')->path($path)) !== $checksum
        || User::count() !== $dbRows['users'] || IdentityVerification::count() !== $dbRows['identity']) {
        throw new RuntimeException('Test restore integrity mismatch.');
    }
    echo json_encode(['recovery' => 'PASS', 'database' => 'pengaduan_unpam_test', 'encrypted_snapshot' => true,
        'file_and_sql_restored' => true, 'checksum_and_counts_match' => true,
        'key_management' => 'ephemeral test key only, not production key recovery']) .PHP_EOL;
} finally {
    if ($path !== null) {
        Storage::disk('private')->delete($path);
    }
    if (is_file($backup)) {
        unlink($backup);
    }
    TestIsolation::verify($app);
    Artisan::call('migrate:fresh', ['--force' => true]);
    echo "Synthetic restore records/files cleaned; no real data was included.\n";
}
