<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Reverb server + application registry
|--------------------------------------------------------------------------
|
| Every value is env-driven with a safe default. Two of them are security
| controls rather than tuning knobs:
|
| allowed_origins  — the browser origins Reverb accepts a socket from. The
|                    wildcard is accepted ONLY in local/testing; production
|                    must list its own origins (REVERB_ALLOWED_ORIGINS, comma
|                    separated) or `realtime:check` fails. A wildcard in
|                    production lets any site open a socket under a visiting
|                    player's session and subscribe to the public draw feed
|                    from a hostile page, and makes the origin check at the
|                    auth endpoint the only line of defence for private ones.
| scaling          — Redis pub/sub between Reverb/application nodes. Required
|                    the moment there is more than one publisher node, or a
|                    socket on node B never hears node A.
|
*/

$environment = (string) env('APP_ENV', 'production');
$localLike = in_array($environment, ['local', 'testing'], true);

$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('REVERB_ALLOWED_ORIGINS', $localLike ? '*' : '')),
), static fn (string $origin): bool => $origin !== ''));

if (! $localLike) {
    // Outside local/testing a wildcard is not an origin list; it is dropped so a
    // misconfigured production node refuses every browser instead of admitting all.
    $origins = array_values(array_filter($origins, static fn (string $origin): bool => $origin !== '*'));
}

if ($origins === [] && $localLike) {
    $origins = ['*'];
}

return [

    'default' => env('REVERB_SERVER', 'reverb'),

    'servers' => [

        'reverb' => [
            'host' => env('REVERB_SERVER_HOST', '0.0.0.0'),
            'port' => (int) env('REVERB_SERVER_PORT', 8080),
            'path' => env('REVERB_SERVER_PATH', ''),
            'hostname' => env('REVERB_HOST'),
            'options' => [
                'tls' => [],
            ],
            'max_request_size' => (int) env('REVERB_MAX_REQUEST_SIZE', 10_000),
            'scaling' => [
                'enabled' => filter_var(env('REVERB_SCALING_ENABLED', false), FILTER_VALIDATE_BOOL),
                'channel' => env('REVERB_SCALING_CHANNEL', 'reverb:cluster'),
                'server' => [
                    'url' => env('REDIS_URL'),
                    'host' => env('REDIS_HOST', '127.0.0.1'),
                    'port' => env('REDIS_PORT', '6379'),
                    'username' => env('REDIS_USERNAME'),
                    'password' => env('REDIS_PASSWORD'),
                    'database' => env('REDIS_DB', '0'),
                    'timeout' => env('REDIS_TIMEOUT', 60),
                ],
            ],
            'pulse_ingest_interval' => (int) env('REVERB_PULSE_INGEST_INTERVAL', 15),
            'telescope_ingest_interval' => (int) env('REVERB_TELESCOPE_INGEST_INTERVAL', 15),
        ],

    ],

    'apps' => [

        'provider' => 'config',

        'apps' => [
            [
                'key' => env('REVERB_APP_KEY'),
                'secret' => env('REVERB_APP_SECRET'),
                'app_id' => env('REVERB_APP_ID'),
                'options' => [
                    'host' => env('REVERB_HOST'),
                    'port' => (int) env('REVERB_PORT', 443),
                    'scheme' => env('REVERB_SCHEME', 'https'),
                    'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
                ],
                'allowed_origins' => $origins,
                'ping_interval' => (int) env('REVERB_APP_PING_INTERVAL', 25),
                'activity_timeout' => (int) env('REVERB_APP_ACTIVITY_TIMEOUT', 30),
                'max_message_size' => (int) env('REVERB_APP_MAX_MESSAGE_SIZE', 10_000),
                'max_connections' => (int) env('REVERB_APP_MAX_CONNECTIONS', 0) ?: null,
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limiting (read by the application's own guards, env-driven)
    |--------------------------------------------------------------------------
    */

    'rate_limits' => [
        'subscribe_per_minute' => (int) env('REVERB_SUBSCRIBE_PER_MINUTE', 120),
        'messages_per_minute' => (int) env('REVERB_MESSAGES_PER_MINUTE', 600),
    ],

];
