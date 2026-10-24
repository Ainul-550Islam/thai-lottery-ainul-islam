<?php

use App\Logging\RedactSensitiveData;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\ProcessIdProcessor;
use Monolog\Processor\WebProcessor;

return [

    'default' => env('LOG_CHANNEL', 'stack'),

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    /*
    |----------------------------------------------------------------------
    | Secret redaction
    |----------------------------------------------------------------------
    |
    | App\Logging\RedactSensitiveData is applied as a channel tap below, so
    | every record written through Laravel's Log facade is scrubbed of
    | credential-shaped keys and values BEFORE it reaches a handler. This is
    | deliberately global rather than opt-in: the log lines that matter most
    | during an incident are the ones nobody wrote through a helper.
    |
    | Set LOG_REDACT_SENSITIVE=false only to debug a redaction bug, and never
    | in production — `ops:preflight` fails a production release when this is
    | off.
    |
    */
    /*
    |----------------------------------------------------------------------
    | Log format
    |----------------------------------------------------------------------
    |
    | `text` (default) is readable in a terminal. `json` emits one JSON object
    | per line, which is what a container log driver, Loki or an ELK pipeline
    | needs in order to index fields instead of grepping prose. Production
    | containers set LOG_FORMAT=json; local development keeps the text format
    | because nobody reads JSON on a laptop.
    |
    | Redaction happens before formatting either way, so a JSON line is not a
    | way around it.
    |
    */
    'format' => env('LOG_FORMAT', 'text'),

    'redaction' => [
        'enabled' => (bool) env('LOG_REDACT_SENSITIVE', true),
        // Extra keys for this deployment (merged with the built-in list).
        'keys' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('LOG_REDACT_EXTRA_KEYS', ''))
        ))),
        'mask' => '[REDACTED]',
    ],

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', env('LOG_STACK', 'single')),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
            'tap' => [RedactSensitiveData::class],
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
            'tap' => [RedactSensitiveData::class],
        ],

        'lottery' => [
            'driver' => 'daily',
            'path' => storage_path('logs/lottery.log'),
            'level' => 'info',
            'days' => 30,
            'replace_placeholders' => true,
            'tap' => [RedactSensitiveData::class],
        ],

        'finance' => [
            'driver' => 'daily',
            'path' => storage_path('logs/finance.log'),
            'level' => 'info',
            'days' => 90,
            'replace_placeholders' => true,
            'tap' => [RedactSensitiveData::class],
        ],

        'security' => [
            'driver' => 'daily',
            'path' => storage_path('logs/security.log'),
            'level' => 'warning',
            'days' => 365,
            'replace_placeholders' => true,
            'tap' => [RedactSensitiveData::class],
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', 'Thai Lottery Log'),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            'level' => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => true,
            // A Slack alert leaves the building. It is the channel an incident
            // response is most likely to paste into a public channel, so it is
            // the last place a credential should survive.
            'tap' => [RedactSensitiveData::class],
        ],

        'stderr' => env('LOG_FORMAT', 'text') === 'json'
            ? [
                'driver' => 'monolog',
                'level' => env('LOG_LEVEL', 'info'),
                'handler' => StreamHandler::class,
                // Without an explicit stream Laravel hands StreamHandler the
                // application log path, so a "stderr" channel quietly wrote to
                // storage/logs/laravel.log. Operators who set LOG_CHANNEL=stderr
                // expect the container's stderr.
                'with' => ['stream' => 'php://stderr'],
                'formatter' => JsonFormatter::class,
                'formatter_with' => [
                    // One object per line; include the timestamp and let the
                    // collector add the rest.
                    'batchMode' => JsonFormatter::BATCH_MODE_NEWLINES,
                    'appendNewline' => true,
                ],
                'processors' => [
                    ProcessIdProcessor::class,
                    WebProcessor::class,
                ],
                'tap' => [RedactSensitiveData::class],
            ]
            : [
                'driver' => 'monolog',
                'level' => env('LOG_LEVEL', 'info'),
                'handler' => StreamHandler::class,
                'with' => ['stream' => 'php://stderr'],
                'formatter' => LineFormatter::class,
                'formatter_with' => [
                    'format' => "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
                ],
                'processors' => [
                    ProcessIdProcessor::class,
                    WebProcessor::class,
                ],
                'tap' => [RedactSensitiveData::class],
            ],

        // Defined here rather than inherited from the framework default, because
        // an inherited channel carries no redaction tap: anything logged to a
        // hosted log service would leave the building intact. Same shape as the
        // framework's channel, plus the tap.
        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'tap' => [RedactSensitiveData::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
            'tap' => [RedactSensitiveData::class],
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
            'tap' => [RedactSensitiveData::class],
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        // Deliberately NOT redacted: the emergency logger is used for messages
        // that must reach an operator even if the application's own services are
        // broken. The trade is intentional and documented here so it is a
        // decision rather than an oversight — nothing in app/ logs a secret
        // through the emergency channel (asserted by the logging tests).
        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

    ],

];
