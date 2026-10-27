<?php

declare(strict_types=1);

/**
 * Standalone test-runtime preflight; runs before Composer installs anything.
 * Never reads credentials, boots Laravel, changes a database or installs tools.
 */
$requiredExtensions = [
    'bcmath',
    'ctype',
    'curl',
    'dom',
    'fileinfo',
    'gd',
    'intl',
    'mbstring',
    'openssl',
    'pdo',
    'pdo_mysql',
    'pdo_sqlite',
    'sodium',
    'sqlite3',
    'tokenizer',
    'xml',
    'zip',
];

$extensions = [];
$missing = [];

foreach ($requiredExtensions as $extension) {
    $extensions[$extension] = extension_loaded($extension);

    if (! $extensions[$extension]) {
        $missing[] = $extension;
    }
}

$jpegEncoder = function_exists('imagejpeg')
    && function_exists('gd_info')
    && (gd_info()['JPEG Support'] ?? false) === true;

$problems = [];

if (PHP_VERSION_ID < 80400 || PHP_VERSION_ID >= 90000) {
    $problems[] = 'The project requires PHP ^8.4 (at least 8.4, below 9.0).';
}

if ($missing !== []) {
    $problems[] = 'Missing PHP extensions: '.implode(', ', $missing).'.';
}

if (! $jpegEncoder) {
    $problems[] = 'GD must provide the JPEG encoder (imagejpeg).';
}

$report = [
    'ready' => $problems === [],
    'php_version' => PHP_VERSION,
    'php_binary' => PHP_BINARY,
    'extensions' => $extensions,
    'missing_extensions' => $missing,
    'jpeg_encoder' => $jpegEncoder,
    'problems' => $problems,
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

exit($problems === [] ? 0 : 1);
