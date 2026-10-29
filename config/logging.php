<?php

use App\Logging\RedactSensitiveData;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\ProcessIdProcessor;
use Monolog\Processor\PsrLogMessageProcessor;
use Monolog\Processor\WebProcessor;

$jsonStderr = strtolower(trim((string) env('LOG_FORMAT', 'text'))) === 'json';

return [

    'default' => env('LOG_CHANNEL', 'stack'),

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', env('LOG_STACK', 'single')),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'tap' => [RedactSensitiveData::class],
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'daily' => [
            'tap' => [RedactSensitiveData::class],
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
        ],

        'lottery' => [
            'tap' => [RedactSensitiveData::class],
            'driver' => 'daily',
            'path' => storage_path('logs/lottery.log'),
            'level' => 'info',
            'days' => 30,
            'replace_placeholders' => true,
        ],

        'finance' => [
            'tap' => [RedactSensitiveData::class],
            'driver' => 'daily',
            'path' => storage_path('logs/finance.log'),
            'level' => 'info',
            'days' => 90,
            'replace_placeholders' => true,
        ],

        'security' => [
            'tap' => [RedactSensitiveData::class],
            'driver' => 'daily',
            'path' => storage_path('logs/security.log'),
            'level' => 'warning',
            'days' => 365,
            'replace_placeholders' => true,
        ],

        'slack' => [
            'tap' => [RedactSensitiveData::class],
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', 'Thai Lottery Log'),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            'level' => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => true,
        ],

        // Laravel supplies this channel even when it is absent from the local
        // config. Declare it explicitly so its outbound records are redacted.
        'papertrail' => [
            'tap' => [RedactSensitiveData::class],
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER') ?: SyslogUdpHandler::class,
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'tap' => [RedactSensitiveData::class],
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'info'),
            'handler' => StreamHandler::class,
            'with' => ['stream' => 'php://stderr'],
            'formatter' => $jsonStderr ? JsonFormatter::class : LineFormatter::class,
            'formatter_with' => $jsonStderr
                ? ['batchMode' => JsonFormatter::BATCH_MODE_NEWLINES, 'appendNewline' => true]
                : ['format' => "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n"],
            'processors' => [
                ProcessIdProcessor::class,
                WebProcessor::class,
            ],
        ],

        'syslog' => [
            'tap' => [RedactSensitiveData::class],
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'tap' => [RedactSensitiveData::class],
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

    ],

];
