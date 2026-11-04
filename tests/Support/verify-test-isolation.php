<?php

require dirname(__DIR__).'/bootstrap.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$result = \Tests\Support\TestIsolation::verify($app);
$tables = $app['db']->selectOne("SELECT COUNT(*) AS total FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users'");
if ($tables->total && $app['db']->table('users')->count() !== 0) {
    throw new RuntimeException('Test schema is not empty; inspect before allowing destructive test setup.');
}
echo json_encode($result + ['preflight' => 'PASS', 'users' => 0], JSON_PRETTY_PRINT).PHP_EOL;
