<?php

declare(strict_types=1);

namespace Tests\Unit\Build;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Integration\Concurrency\RealEngineConcurrencyHarness;

/** A wrong database must be rejected before Laravel's destructive test traits. */
final class TestDatabaseSafetyContractTest extends TestCase
{
    public function test_password_race_refuses_unsafe_database_configuration_before_connecting(): void
    {
        $key = 'CONCURRENCY_TEST_ALLOW_DESTRUCTIVE';
        $original = getenv($key);
        $envPresent = array_key_exists($key, $_ENV);
        $envValue = $_ENV[$key] ?? null;
        $safe = [
            'connection' => 'mysql', 'host' => '127.0.0.1', 'port' => 13307,
            'database' => 'thai_lottery_conc', 'username' => 'concurrency_test', 'password' => 'dummy',
        ];

        try {
            putenv($key.'=1');
            $_ENV[$key] = '1';
            foreach ([
                ['database' => 'production'],
                ['username' => 'root'],
                ['host' => 'database.example'],
            ] as $unsafe) {
                try {
                    RealEngineConcurrencyHarness::run(array_replace($safe, $unsafe));
                    self::fail('Unsafe destructive-test configuration was accepted.');
                } catch (\RuntimeException $exception) {
                    self::assertStringContainsString('require explicit acknowledgement', $exception->getMessage());
                }
            }
            putenv($key.'=0');
            $_ENV[$key] = '0';
            try {
                RealEngineConcurrencyHarness::run($safe);
                self::fail('An unacknowledged destructive test was accepted.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('require explicit acknowledgement', $exception->getMessage());
            }
        } finally {
            $original === false ? putenv($key) : putenv($key.'='.$original);
            if ($envPresent) {
                $_ENV[$key] = $envValue;
            } else {
                unset($_ENV[$key]);
            }
        }
    }

    /** @return array<string, array{string, string, bool}> */
    public static function destructiveTraits(): array
    {
        return [
            'truncation' => [
                'tests/Feature/Payment/WithdrawalGatewayKeyPersistenceTest.php',
                'test_route_is_committed_before_one_external_call_and_survives_retries',
                false,
            ],
            'refresh' => [
                'tests/Feature/Player/PlayerExperienceComprehensiveTest.php',
                'test_33_player_can_request_withdrawal',
                false,
            ],
            'sqlite file symlink' => [
                'tests/Feature/Payment/WithdrawalGatewayKeyPersistenceTest.php',
                'test_route_is_committed_before_one_external_call_and_survives_retries',
                true,
            ],
        ];
    }

    #[DataProvider('destructiveTraits')]
    public function test_test_harness_refuses_before_any_schema_or_data_changes(string $test, string $filter, bool $symlink): void
    {
        $root = dirname(__DIR__, 3);
        $directory = sys_get_temp_dir().'/thai-test-safety-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $database = $directory.'/production.sqlite';
        $connectionPath = $symlink ? $directory.'/thai_lottery_test' : $database;

        try {
            $pdo = new PDO('sqlite:'.$database);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, migration TEXT NOT NULL, batch INTEGER NOT NULL)');
            $pdo->exec('CREATE TABLE safety_marker (value TEXT NOT NULL)');
            $pdo->exec("INSERT INTO safety_marker VALUES ('do not delete')");
            $pdo = null;
            if ($symlink) {
                self::assertTrue(symlink($database, $connectionPath));
            }

            $process = new Process([
                PHP_BINARY, 'vendor/bin/phpunit', '--no-coverage', '--stop-on-defect',
                '--filter', $filter, $test,
            ], $root, [
                'APP_BASE_PATH' => $root,
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => $connectionPath,
                'DB_URL' => '',
            ], null, 60);
            $process->run();

            self::assertNotSame(0, $process->getExitCode(), 'The unsafe child run must be refused.');
            self::assertStringContainsString('ABORTED: tests may only use the disposable', $process->getOutput().$process->getErrorOutput());
            $pdo = new PDO('sqlite:'.$database);
            self::assertSame(['do not delete'], $pdo->query('SELECT value FROM safety_marker')->fetchAll(PDO::FETCH_COLUMN));
            self::assertSame(
                ['migrations', 'safety_marker'],
                $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN),
            );
            $pdo = null;
        } finally {
            if ($symlink && is_link($connectionPath)) {
                unlink($connectionPath);
            }
            if (is_file($database)) {
                unlink($database);
            }
            rmdir($directory);
        }
    }
}
