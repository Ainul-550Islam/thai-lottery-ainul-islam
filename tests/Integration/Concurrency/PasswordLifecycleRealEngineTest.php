<?php

declare(strict_types=1);

namespace Tests\Integration\Concurrency;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Part 56 — the Part 51 guard and Part 52 binding under real engine load.
 *
 * WHY THIS IS SKIPPED BY DEFAULT
 * It needs a disposable MySQL/MariaDB or PostgreSQL server, because the whole
 * point is independent server connections contending for the same row. It
 * runs only when CONCURRENCY_TEST_DSN is set, so a normal `php artisan test`
 * run is unaffected and nothing here can reach a shared database by accident.
 *
 *   CONCURRENCY_TEST_ALLOW_DESTRUCTIVE=1
 *   CONCURRENCY_TEST_DSN="mysql://concurrency_test:pass@127.0.0.1:13306/thai_lottery_conc"
 *   CONCURRENCY_TEST_DSN="pgsql://concurrency_test:pass@127.0.0.1:15432/thai_lottery_conc"
 *
 * Several local engines may be given comma-separated; each is rebuilt from
 * scratch (migrate:fresh). Provision a separate disposable schema and a
 * test-only account with privileges limited to that schema.
 */
final class PasswordLifecycleRealEngineTest extends TestCase
{
    public function createApplication(): Application
    {
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function engines(): array
    {
        $raw = (string) (getenv('CONCURRENCY_TEST_DSN') ?: ($_ENV['CONCURRENCY_TEST_DSN'] ?? ''));

        if (trim($raw) === '') {
            return ['no engine configured' => [[]]];
        }

        $cases = [];

        foreach (explode(',', $raw) as $dsn) {
            $parts = parse_url(trim($dsn));

            if ($parts === false || ($parts['scheme'] ?? '') === '' || ($parts['host'] ?? '') === '') {
                continue;
            }

            $connection = $parts['scheme'] === 'postgres' ? 'pgsql' : $parts['scheme'];

            if (! in_array($connection, ['mysql', 'pgsql', 'mariadb'], true)) {
                continue;
            }

            $cases[$connection.' '.($parts['host']).':'.($parts['port'] ?? '')] = [[
                'connection' => $connection === 'mariadb' ? 'mysql' : $connection,
                'host' => (string) $parts['host'],
                'port' => (int) ($parts['port'] ?? ($connection === 'pgsql' ? 5432 : 3306)),
                'database' => ltrim((string) ($parts['path'] ?? ''), '/'),
                'username' => (string) ($parts['user'] ?? ''),
                'password' => (string) ($parts['pass'] ?? ''),
            ]];
        }

        return $cases === [] ? ['no engine configured' => [[]]] : $cases;
    }

    /**
     * @param  array<string, mixed>  $engine
     */
    #[DataProvider('engines')]
    public function test_password_lifecycle_holds_under_multi_process_load(array $engine): void
    {
        if ($engine === []) {
            self::markTestSkipped('Set CONCURRENCY_TEST_DSN to a disposable MySQL/MariaDB or PostgreSQL database to run this.');
        }

        $report = RealEngineConcurrencyHarness::run($engine);

        $context = json_encode([
            'engine' => $report['engine'],
            'workers' => $report['workers'],
            'loginAttempts' => $report['loginAttempts'],
            'loginSuccesses' => $report['loginSuccesses'],
            'changeAttempts' => $report['changeAttempts'],
            'changesCommitted' => $report['changesCommitted'],
            'expectedFinalPassword' => $report['expectedFinalPassword'],
            'residualWindowHits' => count($report['residualWindowHits']),
            'lockErrors' => $report['lockErrors'],
            'sessionsTotal' => $report['sessionsTotal'],
            'sessionsWithUser' => $report['sessionsWithUser'],
        ], JSON_PRETTY_PRINT);

        // 0. The run itself must have happened.
        self::assertSame([], $report['timedOut'], "workers timed out:\n{$context}");
        self::assertSame([], $report['missingResults'], "workers produced no result:\n".$context."\n".json_encode($report['missingResults']));
        self::assertSame([], $report['workerErrors'], "workers reported errors:\n{$context}\n".json_encode($report['workerErrors']));
        self::assertSame(5, $report['workers'], "not every worker reported:\n{$context}");
        self::assertSame(160, $report['loginAttempts'], "the full login workload did not run:\n{$context}");
        self::assertSame(12, $report['changeAttempts'], "the full password-change workload did not run:\n{$context}");
        self::assertSame(12, $report['changesCommitted'], "every sequential password change must commit:\n{$context}");

        // 1. No stale resurrection: the stored hash validates exactly one
        //    candidate, and it is the last password the changer committed.
        $matching = array_keys(array_filter($report['hashMatches'], static fn (bool $ok): bool => $ok));
        self::assertSame(
            [$report['expectedFinalPassword']],
            $matching,
            "the stored hash must validate only the last committed password:\n{$context}\n".json_encode($report['hashMatches']),
        );

        // 2. No login may succeed with a password that was already superseded
        //    before that login started verifying it.
        self::assertSame(
            [],
            $report['violations'],
            "a login succeeded with an already-superseded password:\n{$context}\n".json_encode($report['violations'], JSON_PRETTY_PRINT),
        );

        // 3. Part 52's guarantee, on real rows written by real processes:
        //    every session row that carries a logged-in user also carries the
        //    password binding. Anonymous rows (failed logins) have neither and
        //    are not a violation.
        self::assertGreaterThan(0, $report['sessionsWithUser'], "no authenticated session row survived to inspect:\n{$context}");
        self::assertSame(
            0,
            $report['userSessionsWithoutBinding'],
            "authenticated session rows exist without a password binding:\n{$context}",
        );

        // 4. Engine-level lock failures are surfaced, not swallowed. A single
        //    changer plus readers should not deadlock; if an engine reports
        //    one, this fails loudly with the driver's own message.
        self::assertSame(
            [],
            $report['lockErrors'],
            "the engine reported lock/deadlock errors:\n{$context}\n".json_encode($report['lockErrors'], JSON_PRETTY_PRINT),
        );
    }
}
