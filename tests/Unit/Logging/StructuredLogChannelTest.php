<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use App\Logging\RedactSensitiveData;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

/**
 * The log *channel* contract, as opposed to the redaction logic itself (covered
 * by RedactingProcessorTest).
 *
 * This file exists because of a bug found while verifying structured logging:
 * `config/logging.php` declared a `stderr` channel with `handler` and
 * `formatter`, but no `with.stream`. Laravel therefore handed the StreamHandler
 * the application log path, so:
 *
 *   LOG_CHANNEL=stderr LOG_FORMAT=json
 *
 * wrote a TEXT line into storage/logs/laravel.log instead of JSON on stderr —
 * and the resolved handler had no redaction tap, so a context array containing
 * `password` was written to disk in the clear. Both halves of that are pinned
 * here: the channel must stream to stderr, and every channel that leaves the
 * process must carry the redaction tap.
 */
final class StructuredLogChannelTest extends TestCase
{
    public function createApplication(): Application
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    /**
     * Re-read config/logging.php under a given LOG_FORMAT.
     *
     * The config file calls storage_path(), so it needs a booted application —
     * the whole file is re-evaluated by rebuilding the application with the
     * environment variable set, which is exactly what a container does on start.
     *
     * @return array<string, array<string, mixed>>
     */
    private function channels(string $logFormat = 'text'): array
    {
        $previous = getenv('LOG_FORMAT');
        putenv('LOG_FORMAT='.$logFormat);
        $_ENV['LOG_FORMAT'] = $logFormat;
        $_SERVER['LOG_FORMAT'] = $logFormat;

        try {
            $this->refreshApplication();

            /** @var array<string, array<string, mixed>> $channels */
            $channels = $this->app['config']->get('logging.channels');
        } finally {
            if ($previous === false) {
                putenv('LOG_FORMAT');
                unset($_ENV['LOG_FORMAT'], $_SERVER['LOG_FORMAT']);
            } else {
                putenv('LOG_FORMAT='.$previous);
                $_ENV['LOG_FORMAT'] = $previous;
                $_SERVER['LOG_FORMAT'] = $previous;
            }
        }

        return $channels;
    }

    public function test_the_stderr_channel_streams_to_stderr_not_to_the_application_log(): void
    {
        $stderr = $this->channels()['stderr'];

        $this->assertSame(StreamHandler::class, $stderr['handler'], 'the stderr channel must use a stream handler');
        $this->assertArrayHasKey('with', $stderr, 'a StreamHandler without an explicit stream falls back to the application log file');
        $this->assertSame('php://stderr', $stderr['with']['stream']);
    }

    public function test_the_stderr_channel_is_json_when_log_format_asks_for_json(): void
    {
        $json = $this->channels('json')['stderr'];

        $this->assertSame(JsonFormatter::class, $json['formatter'], 'containers need one JSON object per line to index fields');
        $this->assertSame(JsonFormatter::BATCH_MODE_NEWLINES, $json['formatter_with']['batchMode']);
        $this->assertTrue($json['formatter_with']['appendNewline']);
    }

    public function test_the_text_format_remains_the_default(): void
    {
        $text = $this->channels('text')['stderr'];

        $this->assertSame(LineFormatter::class, $text['formatter']);
        $this->assertArrayHasKey('format', $text['formatter_with']);
    }

    public function test_every_channel_that_writes_a_log_record_carries_the_redaction_tap(): void
    {
        $exempt = ['emergency'];

        foreach ($this->channels() as $name => $config) {
            if (! is_array($config) || in_array($name, $exempt, true)) {
                continue;
            }

            // `stack` fans out to channels that are themselves tapped; `null`
            // discards records; both are exempt by construction.
            if (in_array($name, ['stack', 'null'], true)) {
                continue;
            }

            $this->assertContains(
                RedactSensitiveData::class,
                $config['tap'] ?? [],
                sprintf('log channel "%s" writes records but does not carry the redaction tap', $name)
            );
        }
    }

    public function test_single_channel_redacts_before_placeholder_interpolation(): void
    {
        $this->channels();
        $path = tempnam(sys_get_temp_dir(), 'logging-single-');
        self::assertIsString($path);

        try {
            $this->app['config']->set('logging.channels.single.path', $path);
            $this->app['log']->forgetChannel('single');
            $this->app['log']->channel('single')->warning('Rejected credential {password}', [
                'password' => 'secret-in-placeholder',
            ]);
            $line = (string) file_get_contents($path);
            self::assertStringNotContainsString('secret-in-placeholder', $line);
            self::assertStringContainsString('[REDACTED]', $line);
        } finally {
            @unlink($path);
        }
    }

    public function test_the_resolved_stderr_channel_applies_json_and_redaction_together(): void
    {
        $this->channels('json');
        $path = tempnam(sys_get_temp_dir(), 'logging-channel-');
        self::assertIsString($path);

        try {
            $this->app['config']->set('logging.channels.stderr.with.stream', $path);
            $this->app['log']->forgetChannel('stderr');
            $this->app['log']->channel('stderr')->info('checkout checked', [
                'password' => 'secret-in-channel', 'amount' => 12.5,
            ]);

            $line = trim((string) file_get_contents($path));
            self::assertStringNotContainsString('secret-in-channel', $line);
            $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('checkout checked', $record['message']);
            self::assertSame('[REDACTED]', $record['context']['password']);
            self::assertSame(12.5, $record['context']['amount']);
        } finally {
            @unlink($path);
        }
    }

    public function test_a_record_written_through_the_stderr_channel_is_json_and_redacted(): void
    {
        $channel = $this->channels('json')['stderr'];

        $stream = fopen('php://memory', 'wb+');

        $handler = new StreamHandler($stream, Level::Debug);
        $handler->setFormatter(new JsonFormatter(
            JsonFormatter::BATCH_MODE_NEWLINES,
            true,
        ));

        $logger = new \Illuminate\Log\Logger(new Logger('local', [$handler]));

        // The tap is what Laravel applies; applying it here proves the pair of
        // classes the channel configures actually redact when combined.
        $tap = new RedactSensitiveData;
        $tap($logger);

        $logger->info('payment captured', ['password' => 'hunter2', 'amount' => 12.5]);

        rewind($stream);
        $line = trim((string) stream_get_contents($stream));
        fclose($stream);

        $this->assertNotSame('', $line, 'the channel wrote nothing');

        /** @var array<string, mixed> $record */
        $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('payment captured', $record['message']);
        $this->assertSame('[REDACTED]', $record['context']['password'], 'a credential survived into a structured log line');
        $this->assertSame(12.5, $record['context']['amount'], 'non-secret context must survive redaction');
    }
}
