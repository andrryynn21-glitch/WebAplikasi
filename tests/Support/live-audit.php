<?php

// This helper refuses every database except the disposable audit database.
use App\Enums\UserRole;
use App\Models\Attachment;
use App\Models\IdentityVerification;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Domain;

require __DIR__.'/../../vendor/autoload.php';

$database = 'pengaduan_unpam_audit_e2e_test';
if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== $database) {
    throw new RuntimeException('Live audit requires the dedicated testing database.');
}

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== $database || $app->configurationIsCached()) {
    throw new RuntimeException('Unsafe or cached database configuration. Audit refused.');
}

$connection = config('database.connections.mysql');
$pdo = new PDO('mysql:host='.$connection['host'].';port='.$connection['port'], $connection['username'], $connection['password']);
$exists = $pdo->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?');
$exists->execute([$database]);

if (($argv[1] ?? '') === 'cleanup') {
    if ($exists->fetchColumn()) {
        foreach ([Attachment::class => 'path', IdentityVerification::class => 'ktm_path'] as $model => $column) {
            foreach ($model::query()->pluck($column)->filter() as $path) {
                if (! str_starts_with($path, 'attachments/') && ! str_starts_with($path, 'identity/')) {
                    throw new RuntimeException('Unexpected audit file path; cleanup refused.');
                }
                if (str_contains($path, '..')) {
                    throw new RuntimeException('Unsafe audit file path; cleanup refused.');
                }
                Storage::disk('private')->delete($path);
            }
        }
        DB::disconnect();
        $pdo->exec('DROP DATABASE `'.$database.'`');
    }
    echo json_encode(['cleaned' => true]);
    exit;
}

if ($exists->fetchColumn()) {
    throw new RuntimeException('Audit database already exists. Inspect and clean it explicitly before a new run.');
}
$pdo->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
Domain::seedCatalog();

$password = 'Audit!'.bin2hex(random_bytes(16)).'7';
$class = Domain::classRoom(['code' => 'AUDIT-A', 'name' => 'Kelas Fiktif Audit A']);
$otherClass = Domain::classRoom(['code' => 'AUDIT-B', 'name' => 'Kelas Fiktif Audit B']);
$accounts = [];
foreach ([
    'admin' => UserRole::SuperAdmin,
    'verifier' => UserRole::IdentityVerifier,
    'leader' => UserRole::ClassLeader,
    'lecturer' => UserRole::LecturerCoordinator,
    'reviewer' => UserRole::LecturerCoordinator,
    'outsider' => UserRole::Student,
] as $key => $role) {
    $user = Domain::user($role, ['name' => 'Akun Fiktif Audit '.ucfirst($key), 'email' => $key.'@audit.example.test', 'password' => $password, 'nim' => 'AUDIT-'.$key]);
    if ($key === 'leader') {
        Domain::leader($user, $class);
    } elseif (in_array($key, ['lecturer', 'reviewer'], true)) {
        Domain::lecturer($user, $class);
    } elseif ($key === 'outsider') {
        Domain::member($user, $otherClass);
    }
    $accounts[$key] = ['id' => $user->id, 'email' => $user->email];
}

// The browser runner consumes credentials in memory, never prints or persists them.
echo json_encode(['password' => $password, 'accounts' => $accounts, 'class_id' => $class->id]);
