<?php

return [

    'name' => env('APP_NAME', 'Thai Lottery'),
    'version' => env('APP_VERSION', '1.0.0'),
    'env' => env('APP_ENV', 'production'),

    /*
    | Shared secret for the detailed health report. When empty, the detailed
    | report is never returned and only the public status is exposed.
    */
    'health_detail_token' => env('HEALTH_DETAIL_TOKEN'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'asset_url' => env('ASSET_URL'),
    'timezone' => env('APP_TIMEZONE', 'Asia/Bangkok'),
    'locale' => env('APP_LOCALE', 'en'),
    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),
    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),
    'cipher' => 'AES-256-CBC',
    'key' => env('APP_KEY'),
    'field_encryption_key' => env('FIELD_ENCRYPTION_KEY'),
    'field_encryption_previous_keys' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('FIELD_ENCRYPTION_PREVIOUS_KEYS', ''))),
        static fn (string $key): bool => $key !== '',
    )),
    'backup_encryption_key' => env('BACKUP_ENCRYPTION_KEY'),
    'previous_keys' => array_filter(
        array_map('trim', explode(',', env('APP_PREVIOUS_KEYS', '')))
    ),
    'maintenance' => [
        'driver' => 'file',
    ],
];
