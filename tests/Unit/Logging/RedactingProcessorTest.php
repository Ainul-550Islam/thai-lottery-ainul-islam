<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use App\Logging\RedactingProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

/**
 * Redaction is a control, not a formatting preference: an unredacted log line
 * during a payment incident is a credential disclosure in a ticket, a chat
 * channel and a log aggregator at once.
 *
 * These tests pin the behaviour that matters — what IS redacted, and the two
 * things that must NOT be (keys that only look sensitive, and values that carry
 * no secret) so the redactor stays usable.
 */
final class RedactingProcessorTest extends TestCase
{
    private function record(array $context = [], array $extra = [], string $message = 'test'): LogRecord
    {
        return new LogRecord(
            datetime: new \DateTimeImmutable('2026-10-24T00:00:00Z'),
            channel: 'testing',
            level: Level::Info,
            message: $message,
            context: $context,
            extra: $extra,
        );
    }

    public function test_credential_keys_are_masked_at_any_depth(): void
    {
        $processor = new RedactingProcessor;

        $result = ($processor)($this->record([
            'user_id' => 42,
            'password' => 'hunter2',
            'nested' => [
                'gateway' => ['api_secret' => 'sk_live_abc', 'currency' => 'THB'],
                'authorization' => 'Bearer abcdefghijklmnop',
            ],
        ]));

        $this->assertSame(42, $result->context['user_id'], 'non-secret context must survive');
        $this->assertSame('[REDACTED]', $result->context['password']);
        $this->assertSame('[REDACTED]', $result->context['nested']['gateway']['api_secret']);
        $this->assertSame('THB', $result->context['nested']['gateway']['currency']);
        $this->assertSame('[REDACTED]', $result->context['nested']['authorization']);
    }

    public function test_key_matching_is_case_and_separator_insensitive(): void
    {
        $processor = new RedactingProcessor;

        $result = ($processor)($this->record([
            'X-Api-Key' => 'abc123',
            'apiKey' => 'abc123',
            'API_TOKEN' => 'abc123',
            'ClientSecret' => 'abc123',
        ]));

        foreach (['X-Api-Key', 'apiKey', 'API_TOKEN', 'ClientSecret'] as $key) {
            $this->assertSame('[REDACTED]', $result->context[$key], $key.' was not redacted');
        }
    }

    public function test_policy_keys_that_only_look_sensitive_are_preserved(): void
    {
        // tokens / token_type / password_min_length are configuration, and
        // redacting them makes the log useless without protecting anything.
        $processor = new RedactingProcessor;

        $result = ($processor)($this->record([
            'token_type' => 'bearer',
            'tokens' => 5,
            'password_min_length' => 12,
        ]));

        $this->assertSame('bearer', $result->context['token_type']);
        $this->assertSame(5, $result->context['tokens']);
        $this->assertSame(12, $result->context['password_min_length']);
    }

    public function test_secrets_embedded_in_a_message_are_masked(): void
    {
        $processor = new RedactingProcessor;

        $result = ($processor)($this->record(
            message: 'Gateway rejected: Authorization: Bearer sk_live_abcdefgh1234 password=Sup3rSecret!'
                .' connecting to mysql://lottery:S3cretPw@db:3306/lottery'
        ));

        $this->assertStringNotContainsString('sk_live_abcdefgh1234', $result->message);
        $this->assertStringNotContainsString('Sup3rSecret!', $result->message);
        $this->assertStringNotContainsString('S3cretPw', $result->message);
        $this->assertStringContainsString('[REDACTED]', $result->message);
    }

    public function test_serialized_json_credentials_in_a_message_are_masked(): void
    {
        $processor = new RedactingProcessor;
        $result = ($processor)($this->record(message: 'Payload: {"password":"json-secret with spaces", "nested":{"api_key":"json-api-secret"}, "token":"json-token-secret"}'));
        foreach (['json-secret with spaces', 'json-api-secret', 'json-token-secret'] as $secret) {
            self::assertStringNotContainsString($secret, $result->message);
        }
        self::assertStringContainsString('[REDACTED]', $result->message);
    }

    public function test_serialized_sensitive_objects_and_arrays_are_not_partially_logged(): void
    {
        $processor = new RedactingProcessor;
        $message = 'payload {"password":{"raw":"nested-secret","other":"second-secret"}, '
            .'"token":["first-secret",{"x":"third-secret"}], "amount":"12.50", "pwd":"fourth-secret"}';
        $result = ($processor)($this->record(message: $message));
        foreach (['nested-secret', 'second-secret', 'first-secret', 'third-secret', 'fourth-secret'] as $secret) {
            self::assertStringNotContainsString($secret, $result->message);
        }
        self::assertStringContainsString('12.50', $result->message);
    }

    public function test_a_data_source_name_in_a_connection_error_is_masked(): void
    {
        $processor = new RedactingProcessor;

        $result = ($processor)($this->record(
            message: 'SQLSTATE[HY000] [1045] Access denied for user "lottery" (using password: YES)'
        ));

        $this->assertStringNotContainsString('using password: YES', $result->message);
    }

    public function test_objects_are_not_stringified_into_the_log(): void
    {
        $processor = new RedactingProcessor;

        $object = new class
        {
            public function __toString(): string
            {
                return 'password=hunter2';
            }
        };

        $result = ($processor)($this->record(['credentials' => $object, 'safe' => $object]));

        $this->assertStringNotContainsString('hunter2', (string) json_encode($result->context));
        $this->assertStringContainsString('object', (string) $result->context['safe']);
    }

    public function test_exception_messages_are_scrubbed_while_the_exception_survives(): void
    {
        $processor = new RedactingProcessor;
        $exception = new \RuntimeException('PDO failed for mysql://user:S3cretPw@db/lottery');

        $result = ($processor)($this->record(['exception' => $exception]));

        $this->assertInstanceOf(\Throwable::class, $result->context['exception']);
        $this->assertStringNotContainsString('S3cretPw', $result->context['exception']->getMessage());
        $this->assertStringContainsString('[REDACTED]', $result->context['exception']->getMessage());
    }

    public function test_json_and_text_formatters_never_receive_unredacted_cause_chains(): void
    {
        foreach ([new \Monolog\Formatter\JsonFormatter, new \Monolog\Formatter\LineFormatter] as $formatter) {
            $stream = fopen('php://memory', 'wb+');
            $handler = new \Monolog\Handler\StreamHandler($stream, \Monolog\Level::Debug);
            $handler->setFormatter($formatter);
            $logger = new \Monolog\Logger('test', [$handler], [new RedactingProcessor]);

            $inner = new \RuntimeException('password=inner-secret');
            $outer = new \Error('Gateway token=outer-secret', 0, $inner);
            $logger->error('request refused', ['exception' => $outer]);
            $logger->error('another refusal', ['exception' => new \RuntimeException('Safe message', 0, $inner)]);

            rewind($stream);
            $output = (string) stream_get_contents($stream);
            fclose($stream);
            self::assertStringNotContainsString('outer-secret', $output);
            self::assertStringNotContainsString('inner-secret', $output);
            self::assertStringContainsString('[REDACTED]', $output);
            self::assertStringContainsString('request refused', $output);
        }
    }

    public function test_json_serializable_exceptions_cannot_smuggle_unredacted_properties(): void
    {
        $exception = new class('Safe message') extends \RuntimeException implements \JsonSerializable
        {
            public function jsonSerialize(): array
            {
                return ['password' => 'serialized-secret'];
            }
        };
        $stream = fopen('php://memory', 'wb+');
        $handler = new \Monolog\Handler\StreamHandler($stream, \Monolog\Level::Debug);
        $handler->setFormatter(new \Monolog\Formatter\JsonFormatter);
        $logger = new \Monolog\Logger('test', [$handler], [new RedactingProcessor]);
        $logger->error('refused', ['exception' => $exception]);
        rewind($stream);
        $output = (string) stream_get_contents($stream);
        fclose($stream);
        self::assertStringNotContainsString('serialized-secret', $output);
        self::assertStringContainsString('Safe message', $output);
    }

    public function test_deep_structures_are_truncated_rather_than_followed_forever(): void
    {
        $processor = new RedactingProcessor;

        $nested = ['level' => 'leaf'];

        for ($i = 0; $i < 30; $i++) {
            $nested = ['nested' => $nested];
        }

        $result = ($processor)($this->record(['payload' => $nested]));

        $this->assertStringContainsString('[TRUNCATED]', (string) json_encode($result->context));
    }
}
