<?php

declare(strict_types=1);

namespace Tests\Unit\Build;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Proves a complete Batch01 lifecycle cannot pollute the next PHPUnit test. */
final class Batch01EnvironmentIsolationTest extends TestCase
{
    public function test_batch_environment_is_restored_for_the_next_test_in_the_same_process(): void
    {
        $root = dirname(__DIR__, 3);
        $process = new Process([
            PHP_BINARY, $root.'/vendor/bin/phpunit', '--configuration', $root.'/phpunit.xml',
            '--fail-on-skipped', '--fail-on-incomplete', '--fail-on-empty-test-suite',
            $root.'/tests/Fixtures/Batch01Environment/EnvironmentSetupTest.php',
            $root.'/tests/Fixtures/Batch01Environment/EnvironmentMutationTest.php',
            $root.'/tests/Fixtures/Batch01Environment/EnvironmentRestorationTest.php',
        ], $root, [
            'APP_BASE_PATH' => $root,
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => 'thai_lottery_test',
            'QUEUE_CONNECTION' => 'redis',
            'SESSION_DRIVER' => 'database',
            'REAL_MONEY_ENABLED' => false,
        ], timeout: 60);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertStringContainsString('OK (3 tests', $process->getOutput());
    }
}
