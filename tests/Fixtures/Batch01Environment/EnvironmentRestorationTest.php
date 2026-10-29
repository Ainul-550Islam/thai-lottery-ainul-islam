<?php

declare(strict_types=1);

namespace Tests\Fixtures\Batch01Environment;

use PHPUnit\Framework\TestCase;

final class EnvironmentRestorationTest extends TestCase
{
    public function test_following_test_sees_the_parent_process_environment(): void
    {
        self::assertSame('mysql', getenv('DB_CONNECTION'));
        self::assertSame('parent-env-marker', $_ENV['DB_CONNECTION']);
        self::assertSame('parent-server-marker', $_SERVER['DB_CONNECTION']);
        self::assertArrayNotHasKey('REAL_MONEY_ENABLED', $_ENV);
        self::assertArrayNotHasKey('REAL_MONEY_ENABLED', $_SERVER);
        self::assertSame('thai_lottery_test', getenv('DB_DATABASE'));
        self::assertSame('redis', getenv('QUEUE_CONNECTION'));
        self::assertSame('database', getenv('SESSION_DRIVER'));
        self::assertFalse(getenv('REAL_MONEY_ENABLED'));
    }
}
