<?php

declare(strict_types=1);

namespace Tests\Fixtures\Batch01Environment;

use PHPUnit\Framework\TestCase;

final class EnvironmentSetupTest extends TestCase
{
    public function test_establishes_distinct_environment_sources(): void
    {
        self::assertSame('mysql', getenv('DB_CONNECTION'));
        $_ENV['DB_CONNECTION'] = 'parent-env-marker';
        $_SERVER['DB_CONNECTION'] = 'parent-server-marker';
        unset($_ENV['REAL_MONEY_ENABLED'], $_SERVER['REAL_MONEY_ENABLED']);
    }
}
