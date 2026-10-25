<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Real-time lane (Reverb / Pusher protocol) — application-level switches
|--------------------------------------------------------------------------
|
| config/broadcasting.php and config/reverb.php hold the driver and server
| settings the framework reads. This file holds what the APPLICATION decides:
| whether the browser bridge is live at all, what the browser may be told
| (never a secret), which notification states may page a phone, and how long
| a notification idempotency key is remembered.
|
| Everything is env-driven with a fail-closed default: a fresh install has no
| live socket (REALTIME_ENABLED=false) and the pages fall back to the existing
| server-rendered figures and polling, instead of a mock bus that pretends.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | When false the layouts emit <meta name="realtime-enabled" content="0">,
    | resources/js/echo.js does not open a socket, and `realtime:check` only
    | reports. When true the broadcast connection must be reverb or pusher.
    |
    */

    'enabled' => filter_var(env('REALTIME_ENABLED', false), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Client block — what the browser is told
    |--------------------------------------------------------------------------
    |
    | Rendered as <meta name="realtime-*"> tags by the authenticated layouts
    | and read by echo.js. ONLY public facts live here: the Reverb app key is a
    | public identifier (the secret stays in config/broadcasting.php and is
    | never part of this block), and the host/port/scheme are what the socket
    | handshake will reveal anyway. Vite's VITE_REVERB_* variables are the
    | build-time fallback echo.js uses when the tags are absent.
    |
    */

    'client' => [
        'key' => (string) env('VITE_REVERB_APP_KEY', env('REVERB_APP_KEY', '')),
        'host' => (string) env('VITE_REVERB_HOST', env('REVERB_HOST', '')),
        'port' => (int) env('VITE_REVERB_PORT', env('REVERB_PORT', 443)),
        'scheme' => (string) env('VITE_REVERB_SCHEME', env('REVERB_SCHEME', 'https')),
        'auth_endpoint' => '/broadcasting/auth',
        'reconnect_min_ms' => max(250, (int) env('REALTIME_RECONNECT_MIN_MS', 1000)),
        'reconnect_max_ms' => max(1000, (int) env('REALTIME_RECONNECT_MAX_MS', 30000)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Deployment shape (read by `realtime:check`)
    |--------------------------------------------------------------------------
    |
    | app_nodes: how many application/queue nodes publish to Reverb. More than
    | one requires Redis scaling on the Reverb side or a subscriber on node B
    | never hears a publish from node A.
    |
    */

    'app_nodes' => max(1, (int) env('REALTIME_APP_NODES', 1)),

    'check' => [
        'connect_timeout_seconds' => max(1, (int) env('REALTIME_CHECK_TIMEOUT_SECONDS', 3)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification routing
    |--------------------------------------------------------------------------
    |
    | notification_sms_states: the withdrawal states that may reach a phone,
    | for players who explicitly opted into SMS for the event AND verified the
    | number. Terminal states only: an SMS per internal hop is spam that costs
    | money per message.
    |
    | dedupe_ttl_seconds: how long a listener remembers that it already acted
    | on (event, row, status). A queue retry or a replayed webhook inside this
    | window sends nothing twice; the notification row's own fingerprint is
    | the durable second guard beyond the window.
    |
    */

    'notification_sms_states' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('REALTIME_NOTIFICATION_SMS_STATES', 'completed,rejected,failed')),
    ))),

    'dedupe_ttl_seconds' => max(60, (int) env('REALTIME_NOTIFICATION_DEDUPE_TTL', 86400)),

];
