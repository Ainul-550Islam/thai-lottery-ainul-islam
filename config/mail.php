<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Mail
|--------------------------------------------------------------------------
|
| DEFAULT MAILER is env-driven. `log` is the development default and delivers
| nothing; a production deployment sets MAIL_MAILER=smtp (or `failover`, below).
|
| HOST/PORT DEFAULTS. The smtp mailer's fallbacks used to be 127.0.0.1:2525,
| which is a local Mailpit; a production box that forgot MAIL_HOST connected
| to nothing on its own loopback and every player mail failed silently inside
| a queue worker. Outside local/testing the defaults are therefore empty/587
| so the misconfiguration is a visible transport error, never a quiet loop-
| back. Local and testing behaviour is unchanged.
|
| FAILOVER is an opt-in named mailer: MAIL_MAILER=failover tries the primary
| smtp, then the secondary smtp (MAIL_SECONDARY_*), then `log` so the message
| text is at least preserved for an operator. It is never selected implicitly.
|
*/

$localLike = in_array((string) env('APP_ENV', 'production'), ['local', 'testing'], true);

return [

    'default' => env('MAIL_MAILER', 'log'),

    'mailers' => [
        'smtp' => [
            'transport' => 'smtp',
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', $localLike ? '127.0.0.1' : ''),
            'port' => (int) env('MAIL_PORT', $localLike ? 2525 : 587),
            'encryption' => env('MAIL_ENCRYPTION'),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => (int) env('MAIL_TIMEOUT', 15),
            'local_domain' => env('MAIL_EHLO_DOMAIN'),
        ],

        'smtp_secondary' => [
            'transport' => 'smtp',
            'url' => env('MAIL_SECONDARY_URL'),
            'host' => env('MAIL_SECONDARY_HOST', ''),
            'port' => (int) env('MAIL_SECONDARY_PORT', 587),
            'encryption' => env('MAIL_SECONDARY_ENCRYPTION'),
            'username' => env('MAIL_SECONDARY_USERNAME'),
            'password' => env('MAIL_SECONDARY_PASSWORD'),
            'timeout' => (int) env('MAIL_SECONDARY_TIMEOUT', 15),
            'local_domain' => env('MAIL_EHLO_DOMAIN'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => array_values(array_filter([
                'smtp',
                (string) env('MAIL_SECONDARY_HOST', '') !== '' || (string) env('MAIL_SECONDARY_URL', '') !== '' ? 'smtp_secondary' : null,
                'log',
            ])),
            'retry_after' => (int) env('MAIL_FAILOVER_RETRY_AFTER', 60),
        ],
    ],

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'no-reply@thai-lottery.test'),
        'name' => env('MAIL_FROM_NAME', 'Thai Lottery'),
    ],

];
