<?php

declare(strict_types=1);

namespace Tests\Fixtures\Batch01Environment;

use Tests\Feature\Payment\Batch01TestCase;

final class EnvironmentMutationTest extends Batch01TestCase
{
    public function test_batch_case_uses_its_own_isolated_environment(): void
    {
        self::assertSame('sqlite', getenv('DB_CONNECTION'));
        self::assertSame(':memory:', getenv('DB_DATABASE'));
        self::assertSame('sync', getenv('QUEUE_CONNECTION'));
        self::assertSame('false', getenv('REAL_MONEY_ENABLED'));
    }
}
