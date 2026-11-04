<?php

require_once dirname(__DIR__).'/vendor/autoload.php';

// Configure only the test process. Never write .env or use the real private disk.
$storage = dirname(__DIR__).'/storage/framework/pilot-testing';
foreach ([$storage, $storage.'/app', $storage.'/framework', $storage.'/logs',
    $storage.'/app/private', $storage.'/app/public', $storage.'/framework/cache',
    $storage.'/framework/cache/data', $storage.'/framework/sessions', $storage.'/framework/views'] as $directory) {
    if (is_link($directory)) {
        throw new RuntimeException('Test storage symlink refused.');
    }
    if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
        throw new RuntimeException('Unable to create isolated test storage.');
    }
}
$_ENV['LARAVEL_STORAGE_PATH'] = $_SERVER['LARAVEL_STORAGE_PATH'] = $storage;
putenv('LARAVEL_STORAGE_PATH='.$storage);
