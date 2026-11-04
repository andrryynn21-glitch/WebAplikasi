<?php

declare(strict_types=1);

// Build from an extracted, clean CI runtime artifact, never from a live installation.
if (PHP_SAPI !== 'cli' || $argc !== 3) {
    fwrite(STDERR, "Usage: php .github/package-infinityfree.php CLEAN_RUNTIME OUTPUT.zip\n");
    exit(1);
}

$source = realpath($argv[1]);
$output = $argv[2];
$created = false;
$zip = new ZipArchive;

try {
    if ($source === false || ! is_dir($source) || ! is_dir(dirname($output))
        || ! str_ends_with($output, '.zip') || file_exists($output) || file_exists($output.'.sha256')) {
        throw new RuntimeException('Source/target invalid, or output already exists. Refusing overwrite.');
    }
    $outputParent = realpath(dirname($output));
    if ($outputParent === $source || str_starts_with($outputParent.'/', $source.'/')) {
        throw new RuntimeException('Output must be outside the source directory.');
    }

    $roots = ['app', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes', 'storage', 'vendor'];
    $rootFiles = ['artisan', 'composer.json', 'composer.lock'];
    $entries = [];
    $bytes = 0;
    $directories = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $file) {
        $path = substr($file->getPathname(), strlen($source) + 1);
        if ($file->isLink() || (! $file->isDir() && ! $file->isFile())) {
            throw new RuntimeException('Links and special files are not allowed: '.$path);
        }
        if ($path === '.env.production.example' && $file->isFile()) {
            continue;
        }
        $root = explode('/', $path)[0];
        if ((! in_array($root, $roots, true) && ! in_array($path, $rootFiles, true))
            || preg_match('~(^|/)(?:\.env(?:\..*)?|auth\.json)$~', $path)
            || preg_match('~\.(?:sql|sqlite(?:-wal|-shm)?|pem|key|p12|pfx)$~i', $path)
            || $path === 'public/hot' || str_starts_with($path, 'public/storage')
            || (str_starts_with($path, 'bootstrap/cache/') && $file->isFile() && $file->getFilename() !== '.gitignore')
            || (str_starts_with($path, 'storage/') && $file->isFile() && $file->getFilename() !== '.gitignore')) {
            throw new RuntimeException('Private/runtime/unexpected file refused: '.$path);
        }
        if ($file->isDir()) {
            $entries[$path.'/'] = null;
            $directories++;

            continue;
        }
        $limit = $file->getFilename() === '.htaccess' ? 10000
            : (in_array(strtolower($file->getExtension()), ['php', 'js', 'html', 'htm'], true) ? 1000000 : 10000000);
        if ($file->getSize() > $limit) {
            throw new RuntimeException('Hosting file-size limit exceeded: '.$path);
        }
        $entries[$path] = $file->getPathname();
        $bytes += $file->getSize();
    }

    foreach (['artisan', 'bootstrap/app.php', 'public/index.php', 'public/.htaccess', 'public/build/manifest.json', 'vendor/autoload.php'] as $required) {
        if (! isset($entries[$required])) {
            throw new RuntimeException('Required runtime file missing: '.$required);
        }
    }
    if ($zip->open($output, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
        throw new RuntimeException('Cannot create ZIP.');
    }
    $created = true;
    ksort($entries);
    foreach ($entries as $path => $file) {
        $added = $file === null ? $zip->addEmptyDir($path) : $zip->addFile($file, $path);
        if (! $added) {
            throw new RuntimeException('Cannot package: '.$path);
        }
    }
    $protected = array_values(array_diff($roots, ['public']));
    foreach ($protected as $directory) {
        if (! $zip->addFromString($directory.'/.htaccess', "Require all denied\n")) {
            throw new RuntimeException('Cannot protect private directory: '.$directory);
        }
    }
    foreach (['.htaccess' => 'infinityfree.htaccess', '.env.production.example' => 'infinityfree.env.example'] as $target => $template) {
        if (! $zip->addFile(__DIR__.'/'.$template, $target)) {
            throw new RuntimeException('Cannot add hosting configuration.');
        }
    }
    if (! $zip->close()) {
        throw new RuntimeException('Cannot finalize ZIP.');
    }
    clearstatcache(true, $output);
    if (filesize($output) > 10000000) {
        throw new RuntimeException('ZIP exceeds the 10 MB upload limit; use verified expanded FTP transfer instead.');
    }
    if ($zip->open($output, ZipArchive::CHECKCONS) !== true) {
        throw new RuntimeException('ZIP consistency check failed.');
    }
    $entryCount = $zip->numFiles;
    $zip->close();
    $sha256 = hash_file('sha256', $output);
    if (file_put_contents($output.'.sha256', $sha256.'  '.basename($output)."\n") === false) {
        throw new RuntimeException('Cannot write checksum.');
    }
    echo json_encode([
        'archive' => basename($output), 'entries' => $entryCount,
        'source_directories' => $directories, 'source_bytes' => $bytes,
        'zip_bytes' => filesize($output), 'sha256' => $sha256,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    if ($created) {
        try {
            $zip->close();
        } catch (Throwable) {
            // The archive may already be closed when validation fails.
        }
        @unlink($output);
        @unlink($output.'.sha256');
    }
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
