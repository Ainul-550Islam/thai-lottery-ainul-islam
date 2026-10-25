<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Broadcasting Configuration
|--------------------------------------------------------------------------
|
| DEFAULT CONNECTION
| Env-driven. `log` is a DEVELOPMENT convenience only: it writes every
| broadcast to the log file and delivers nothing to a browser, so a production
| deployment on `log` has a dead real-time lane that looks healthy from the
| server. `php artisan realtime:check` fails when REALTIME_ENABLED is on and
| the connection is log/null. The production compose file and k8s manifests
| set BROADCAST_CONNECTION=reverb.
|
| AFTER COMMIT
| Every money event in app/Events declares $afterCommit = true (see
| App\Events\Concerns\BroadcastsOnNotificationsLane), which is the property the
| framework's BroadcastEvent job reads; the connection-level flag below is the
| belt to that brace for any future event that forgets.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    */

    'default' => env('BROADCAST_CONNECTION', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Broadcast Connections
    |--------------------------------------------------------------------------
    |
    | Reverb speaks the Pusher protocol, so the pusher connection below is kept
    | as a drop-in alternative for a hosted provider without changing app code.
    |
    */

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST', 'localhost'),
                'port' => (int) env('REVERB_PORT', 8080),
                'scheme' => env('REVERB_SCHEME', 'http'),
                'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
            ],
            // Guzzle options for the server -> Reverb HTTP publish. A publish
            // that hangs holds a notifications-lane worker; bound it.
            'client_options' => [
                'timeout' => (float) env('REVERB_PUBLISH_TIMEOUT', 5),
                'connect_timeout' => (float) env('REVERB_PUBLISH_CONNECT_TIMEOUT', 2),
                'verify' => filter_var(env('REVERB_PUBLISH_VERIFY_TLS', true), FILTER_VALIDATE_BOOL),
            ],
            'afterCommit' => true,
        ],

        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'host' => env('PUSHER_HOST') ?: 'api-'.env('PUSHER_APP_CLUSTER', 'mt1').'.pusher.com',
                'port' => (int) env('PUSHER_PORT', 443),
                'scheme' => env('PUSHER_SCHEME', 'https'),
                'encrypted' => true,
                'useTLS' => env('PUSHER_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                'timeout' => (float) env('PUSHER_PUBLISH_TIMEOUT', 5),
                'connect_timeout' => (float) env('PUSHER_PUBLISH_CONNECT_TIMEOUT', 2),
            ],
            'afterCommit' => true,
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
