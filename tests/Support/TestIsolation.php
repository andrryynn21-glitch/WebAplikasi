<?php

namespace Tests\Support;

use Illuminate\Foundation\Application;
use RuntimeException;

final class TestIsolation
{
    public static function verify(Application $app): array
    {
        $expected = $app->basePath('storage/framework/pilot-testing');
        $config = $app['config']->get('database.connections.mysql');
        if (! $app->environment('testing') || $app->configurationIsCached()
            || $app['config']->get('database.default') !== 'mysql'
            || $config['database'] !== 'pengaduan_unpam_test'
            || $config['host'] !== '127.0.0.1' || (string) $config['port'] !== '3306'
            || ! empty($config['url']) || ! empty($config['unix_socket'])
            || $app->storagePath() !== $expected
            || $app['config']->get('mail.default') !== 'array'
            || $app['config']->get('queue.default') !== 'sync'
            || $app['config']->get('cache.default') !== 'array'
            || $app['config']->get('session.driver') !== 'array') {
            throw new RuntimeException('Unsafe test configuration; no test mutations allowed.');
        }
        foreach (['local' => 'private', 'private' => 'private', 'public' => 'public'] as $disk => $folder) {
            $root = $app['config']->get("filesystems.disks.{$disk}.root");
            if ($root !== $expected.'/app/'.$folder || is_link($root) || realpath($root) !== $root) {
                throw new RuntimeException('Test disk isolation could not be verified.');
            }
        }
        $db = $app['db']->connection();
        if ($db->selectOne('SELECT DATABASE() AS name')->name !== 'pengaduan_unpam_test') {
            throw new RuntimeException('Actual test connection differs from the approved test schema.');
        }

        return ['database' => 'pengaduan_unpam_test', 'storage' => $expected, 'mail' => 'array',
            'cache' => 'array', 'session' => 'array', 'queue' => 'sync', 'isolated' => true];
    }
}
