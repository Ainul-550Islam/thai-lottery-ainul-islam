<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use App\Logging\RedactingProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class SecretCollectionTest extends TestCase
{
    public function test_plural_token_fields_allow_counts_but_not_secret_collections_or_text(): void
    {
        $processor = new RedactingProcessor;
        $make = static fn (array $context, string $message) => new LogRecord(
            datetime: new \DateTimeImmutable('2026-10-29T00:00:00Z'),
            channel: 'test', level: Level::Info, message: $message, context: $context, extra: [],
        );
        $count = $processor($make(['tokens' => 5], '{"tokens":5}'));
        self::assertSame(5, $count->context['tokens']);
        self::assertStringContainsString('"tokens":5', $count->message);
        $array = $processor($make(['tokens' => ['refresh' => 'context-token-secret']], '{"tokens":["array-token-secret"]}'));
        self::assertStringNotContainsString('context-token-secret', json_encode($array->context, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('array-token-secret', $array->message);
        $text = $processor($make(['tokens' => 'text-token-secret'], '{"tokens":"string-token-secret"} tokens=plain-token-secret'));
        self::assertStringNotContainsString('text-token-secret', json_encode($text->context, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('string-token-secret', $text->message);
        self::assertStringNotContainsString('plain-token-secret', $text->message);
    }
}
