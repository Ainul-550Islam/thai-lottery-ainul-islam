<?php

declare(strict_types=1);

namespace Tests\Feature\Logging;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/** Real Laravel logger resolution and listeners, not a processor-only mock. */
final class LoggingFallbackSafetyTest extends TestCase
{
    public function test_failed_channel_resolution_does_not_log_emergency_credentials(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'emergency-log-');
        self::assertIsString($path);
        try {
            config(['logging.channels.emergency.path' => $path]);
            Log::channel('deliberately-undefined')->error('Provider rejected password=inline-secret', [
                'password' => 'context-secret', 'nested' => ['api_key' => 'nested-secret'],
            ]);
            $line = (string) file_get_contents($path);
            self::assertStringContainsString('Provider rejected', $line);
            foreach (['inline-secret', 'context-secret', 'nested-secret'] as $secret) {
                self::assertStringNotContainsString($secret, $line);
            }
        } finally {
            @unlink($path);
        }
    }

    public function test_on_demand_channel_without_explicit_tap_still_redacts(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ondemand-log-');
        self::assertIsString($path);
        try {
            $logger = Log::build(['driver' => 'single', 'path' => $path, 'level' => 'debug']);
            $logger->withContext(['password' => 'shared-secret']);
            $logger->warning('On-demand payload', ['nested' => ['token' => 'payload-secret']]);
            $line = (string) file_get_contents($path);
            self::assertStringContainsString('On-demand payload', $line);
            self::assertStringNotContainsString('shared-secret', $line);
            self::assertStringNotContainsString('payload-secret', $line);
        } finally {
            @unlink($path);
        }
    }

    public function test_framework_extra_context_is_redacted_for_an_on_demand_channel(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'extra-log-');
        self::assertIsString($path);
        try {
            Context::add('api_key', 'framework-extra-secret');
            Log::build(['driver' => 'single', 'path' => $path, 'level' => 'debug'])->warning('Extra context');
            $line = (string) file_get_contents($path);
            self::assertStringContainsString('Extra context', $line);
            self::assertStringNotContainsString('framework-extra-secret', $line);
            self::assertStringContainsString('[REDACTED]', $line);
        } finally {
            Context::flush();
            @unlink($path);
        }
    }

    public function test_on_demand_stack_redacts_both_output_and_listener_context(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'stack-log-');
        self::assertIsString($path);
        try {
            config(['logging.channels.single.path' => $path]);
            Log::forgetChannel('single');
            $events = [];
            Event::listen(MessageLogged::class, static function (MessageLogged $event) use (&$events): void {
                $events[] = [$event->message, $event->context];
            });
            Log::stack(['single'])->withContext(['password' => 'stack-shared-secret'])
                ->warning('token=stack-inline-secret', ['api_key' => 'stack-context-secret']);
            $observed = (string) file_get_contents($path).json_encode($events, JSON_THROW_ON_ERROR);
            self::assertNotEmpty($events);
            foreach (['stack-shared-secret', 'stack-inline-secret', 'stack-context-secret'] as $secret) {
                self::assertStringNotContainsString($secret, $observed);
            }
        } finally {
            @unlink($path);
        }
    }

    public function test_custom_psr_driver_receives_only_redacted_payloads(): void
    {
        $recorded = [];
        config(['logging.channels.synthetic_custom' => [
            'driver' => 'custom',
            'tap' => [\App\Logging\RedactSensitiveData::class],
            'via' => static function () use (&$recorded) {
                return new class($recorded) extends \Psr\Log\AbstractLogger {
                    private $records;
                    public function __construct(array &$records) { $this->records = &$records; }
                    public function log($level, string|\Stringable $message, array $context = []): void
                    {
                        $this->records[] = [$level, (string) $message, $context];
                    }
                };
            },
        ]]);
        Log::channel('synthetic_custom')->withContext(['token' => 'custom-shared-secret'])
            ->error('provider password=custom-inline-secret', ['password' => 'custom-payload-secret']);
        self::assertNotEmpty($recorded);
        $output = json_encode($recorded, JSON_THROW_ON_ERROR);
        foreach (['custom-shared-secret', 'custom-inline-secret', 'custom-payload-secret'] as $secret) {
            self::assertStringNotContainsString($secret, $output);
        }
    }

    public function test_log_events_do_not_expose_secrets_before_the_channel_processor(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'event-log-');
        self::assertIsString($path);
        try {
            config(['logging.channels.single.path' => $path]);
            Log::forgetChannel('single');
            $events = [];
            Event::listen(MessageLogged::class, static function (MessageLogged $event) use (&$events): void {
                $events[] = [$event->message, $event->context];
            });
            Log::channel('single')->withContext(['session_id' => 'shared-session-secret'])
                ->warning('Login password=event-inline-secret', ['password' => 'event-context-secret']);
            self::assertNotEmpty($events);
            $observed = json_encode($events, JSON_THROW_ON_ERROR);
            foreach (['shared-session-secret', 'event-inline-secret', 'event-context-secret'] as $secret) {
                self::assertStringNotContainsString($secret, $observed);
            }
        } finally {
            @unlink($path);
        }
    }
}
