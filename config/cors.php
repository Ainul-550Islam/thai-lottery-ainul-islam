<?php

$environment = strtolower(trim((string) env('APP_ENV', 'production')));
$developmentOrigins = in_array($environment, ['local', 'testing'], true)
    ? 'http://localhost,http://localhost:3000,http://localhost:5173,http://127.0.0.1:8000'
    : '';
$rawOrigins = (string) env('CORS_ALLOWED_ORIGINS', $developmentOrigins);
$allowedOrigins = array_values(array_unique(array_filter(
    array_map(static fn (string $origin): string => rtrim(trim($origin), '/'), explode(',', $rawOrigins)),
    static fn (string $origin): bool => $origin !== '' && $origin !== '*',
)));

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Production CORS is deny-by-default. Deployments must name exact origins
    | in CORS_ALLOWED_ORIGINS; localhost defaults apply only to local/testing.
    | Credentials are never enabled, and wildcard origins are discarded.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $allowedOrigins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Content-Type',
        'X-Requested-With',
        'Authorization',
        'X-Correlation-ID',
        'X-Request-ID',
        'X-Idempotency-Key',
    ],

    'exposed_headers' => [
        'X-Correlation-ID',
        'X-Request-ID',
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
        'Retry-After',
    ],

    'max_age' => 86400,

    'supports_credentials' => false,

];
