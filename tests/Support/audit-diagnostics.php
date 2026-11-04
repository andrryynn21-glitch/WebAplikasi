<?php

// Diagnostics print classifications only, never log messages or credential values.
$root = dirname(__DIR__, 2);
$log = $root.'/storage/logs/laravel.log';
$counts = [];
$range = ['first' => null, 'last' => null];
if (is_file($log)) {
    $stream = fopen($log, 'r');
    while (($line = fgets($stream)) !== false) {
        if (! preg_match('/^\[([0-9 :\-]+)\] [a-z]+\.([A-Z]+):/', $line, $match)) {
            continue;
        }
        $range['first'] ??= $match[1];
        $range['last'] = $match[1];
        $category = match (true) {
            str_contains($line, 'Audit unavailable') => 'synthetic_audit_failure',
            str_contains($line, 'Synthetic') => 'synthetic_persistence_failure',
            str_contains($line, 'Route [login] not defined') => 'historical_missing_login_route',
            str_contains($line, 'normalize') => 'historical_workflow_method',
            str_contains($line, 'ReportSummaryResource') => 'historical_resource_import',
            str_contains($line, 'SQLSTATE') => 'database_exception',
            str_contains($line, 'Peninjau harus berbeda') => 'historical_reviewer_validation',
            str_contains($line, 'Banding sudah memiliki') => 'historical_reviewer_validation',
            str_contains($line, 'InvalidArgumentException') => 'invalid_argument',
            str_contains($line, 'bootstrap-admin') => 'console_bootstrap',
            default => preg_match('/\b([A-Za-z_][A-Za-z_0-9]*Exception)\b/', $line, $exception)
                ? 'exception_type_'.$exception[1] : 'other_'.$match[2],
        };
        $counts[$category] = ($counts[$category] ?? 0) + 1;
    }
    fclose($stream);
}

$matches = [];
foreach (['app', 'config', 'database', 'resources', 'routes'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (! $file->isFile() || ! in_array($file->getExtension(), ['php', 'js', 'css'], true)) {
            continue;
        }
        $content = file($file->getPathname());
        foreach ($content as $index => $line) {
            foreach ([
                'private_key_literal' => '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/',
                'aws_key_literal' => '/\b(?:AKIA|ASIA)[A-Z0-9]{16}\b/',
                'provider_key_literal' => '/\b(?:sk-[A-Za-z0-9]{24,}|ghp_[A-Za-z0-9]{30,})\b/',
                'credential_literal' => '/[\x27\x22](?:password|secret|api_key)[\x27\x22]\s*=>\s*[\x27\x22][^\x27\x22]{6,}[\x27\x22]/i',
            ] as $type => $pattern) {
                if ($type === 'credential_literal' && preg_match('/[\x27\x22]password[\x27\x22]\s*=>\s*[\x27\x22]hashed[\x27\x22]/', $line)) {
                    continue;
                }
                if (preg_match($pattern, $line)) {
                    $matches[] = ['file' => substr($file->getPathname(), strlen($root) + 1), 'line' => $index + 1, 'classification' => $type];
                }
            }
        }
    }
}

$publicLinks = [];
$public = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/public', FilesystemIterator::SKIP_DOTS));
foreach ($public as $file) {
    if ($file->isLink()) {
        $target = realpath($file->getPathname());
        $publicLinks[] = ['path' => substr($file->getPathname(), strlen($root) + 1),
            'points_to_private_storage' => $target !== false && str_starts_with($target, $root.'/storage/app/private')];
    }
}
echo json_encode(['log_header_range' => $range, 'log_categories' => $counts,
    'source_secret_pattern_matches' => $matches, 'public_symlinks' => $publicLinks], JSON_PRETTY_PRINT).PHP_EOL;
