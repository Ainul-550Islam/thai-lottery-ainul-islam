<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Services\Security\MoneyWriteCoverageService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Part 69 — wallet money is written only by the finance engine.
 *
 * WHY IT MATTERS
 * The finance engine is the only writer that also produces a ledger row, takes
 * the row lock and passes the settlement guards. A balance written anywhere
 * else moves money with no record of it moving.
 *
 * WHAT WAS MEASURED
 * Scanning `app/` (models excluded) found **11 money writes, all of them
 * assignments**: 5 in `Finance/WalletService.php` (lines 189, 237, 384, 438, 485)
 * and 6 in `Agent/AgentOnboardingService.php` (lines 312–317) — the latter being
 * `ensureAgentWallet()` setting a wallet that does not exist yet to '0.00', i.e.
 * initialisation rather than mutation, so it is a named exception with its
 * reason rather than a silent pass.
 *
 * A correction worth keeping: `Finance/WalletHoldService.php` was expected to
 * hold array-shaped writes at lines 337, 345, 361, 362, 380 and 382. It does
 * not. Every one of those `'balance' => …` entries is a context array passed to
 * `FinancialException::withCode()`, and line 398 belongs to `snapshot()`'s
 * `return [`. The file writes no money column at all, which is why it appears in
 * the allowlist but contributes no write site.
 *
 * WHAT IS PROVEN
 *  1. No money write outside the finance engine except the one named exception.
 *  2. The exception list is exactly that file, and the flagged lines are exactly
 *     the six initialisation assignments — so neither a new exception nor a new
 *     write inside the existing one can slip through.
 *  3. No request class accepts a client-supplied `balance`.
 *  4. `WalletHoldService::snapshot()`'s `return ['balance' => …]` is NOT counted
 *     as a write — without this the scan would be reporting payloads, and every
 *     number in the report would be inflated.
 *  5. The detector really detects: a synthetic rogue file is reported for all
 *     three defects at the exact file and line.
 */
final class MoneyWriteCoverageTest extends TestCase
{
    #[Test]
    public function no_money_write_happens_outside_the_finance_engine(): void
    {
        $report = app(MoneyWriteCoverageService::class)->report();

        $this->assertGreaterThan(0, $report['scanned_files'], 'nothing was scanned');
        $this->assertNotEmpty($report['write_sites'], 'no money write sites were found at all');
        $this->assertNotEmpty($report['covered']);
        $this->assertSame(
            [],
            $report['uncovered'],
            'money written outside the finance engine: '.implode(', ', $report['uncovered']),
        );
    }

    #[Test]
    public function the_single_exception_is_named_and_confined_to_wallet_initialisation(): void
    {
        $service = app(MoneyWriteCoverageService::class);

        $this->assertSame(
            ['app/Services/Agent/AgentOnboardingService.php'],
            array_keys($service->knownExceptions()),
            'a new money-write exception was added without updating this test and its reason',
        );

        $exceptions = $service->report()['known_exceptions'];

        // Six writes, one per money column. Pinning line numbers instead would
        // break on any edit above the method, including a comment.
        $columns = array_map(
            static fn (string $label): string => trim(substr($label, strrpos($label, ' ') + 1)),
            $exceptions,
        );

        sort($columns);

        $this->assertSame(
            ['balance', 'locked_balance', 'total_deposited', 'total_wagered', 'total_withdrawn', 'total_won'],
            $columns,
        );

        // And every one of them sits inside the "wallet does not exist yet"
        // branch, which is what makes initialisation different from a mutation.
        $source = (string) file_get_contents(
            base_path('app/Services/Agent/AgentOnboardingService.php'),
        );

        $start = strpos($source, 'private function ensureAgentWallet');
        $this->assertNotFalse($start, 'ensureAgentWallet() disappeared');

        $end = strpos($source, "\n    }\n", (int) $start);
        $body = substr($source, (int) $start, $end === false ? null : $end - (int) $start);

        $guard = strpos($body, 'if (! $wallet instanceof Wallet) {');
        $this->assertNotFalse($guard, 'the new-wallet guard disappeared');

        foreach (['balance', 'locked_balance', 'total_deposited', 'total_withdrawn', 'total_wagered', 'total_won'] as $column) {
            $write = strpos($body, '$wallet->'.$column." = '0.00';");

            $this->assertNotFalse($write, $column.' is no longer initialised to zero here');
            $this->assertGreaterThan($guard, $write, $column.' is written outside the new-wallet guard');
        }
    }

    #[Test]
    public function no_request_accepts_a_client_supplied_balance(): void
    {
        $report = app(MoneyWriteCoverageService::class)->report();

        $this->assertSame(
            [],
            $report['client_writable_balance'],
            'a money form accepts a client-supplied balance: '.implode(', ', $report['client_writable_balance']),
        );

        foreach ([
            'app/Http/Requests/Payment/DepositRequest.php',
            'app/Http/Requests/Withdrawal/CreateWithdrawalRequest.php',
        ] as $relative) {
            $source = (string) file_get_contents(base_path($relative));

            $this->assertMatchesRegularExpression(
                "/['\"]balance['\"]\s*=>\s*\[\s*'prohibited'/",
                $source,
                $relative.' no longer marks balance as prohibited',
            );
        }
    }

    #[Test]
    public function payloads_and_exception_context_are_not_counted_as_writes(): void
    {
        $sites = app(MoneyWriteCoverageService::class)->report()['write_sites'];

        // WalletHoldService is full of 'balance' => … entries, none of which is a
        // write: they are FinancialException::withCode() context arrays and
        // snapshot()'s return payload. Counting them would inflate every number
        // in the report and hide a real one.
        $holdWrites = array_values(array_filter(
            $sites,
            static fn (array $site): bool => $site['file'] === 'app/Services/Finance/WalletHoldService.php',
        ));

        $this->assertSame([], $holdWrites, 'exception context or payload counted as a write');

        // And the scan is not simply blind to the file: it read it, and the
        // assignments it does find elsewhere are shaped the same way.
        $assignments = array_values(array_filter(
            $sites,
            static fn (array $site): bool => $site['shape'] === 'assignment',
        ));

        $this->assertCount(11, $assignments);
        $this->assertSame(11, count($sites), 'every measured money write is an assignment');
    }

    #[Test]
    public function the_detector_reports_a_rogue_writer_and_a_client_writable_balance(): void
    {
        $tmp = sys_get_temp_dir().'/money-coverage-'.bin2hex(random_bytes(4));
        mkdir($tmp.'/app/Services/Rogue', 0o777, true);

        file_put_contents(
            $tmp.'/app/Services/Rogue/CreditService.php',
            <<<'PHP'
            <?php

            namespace App\Services\Rogue;

            class CreditService
            {
                public function credit($wallet, $amount)
                {
                    $wallet->balance = $amount;
                    $wallet->save();

                    $wallet->newQuery()->update([
                        'balance' => $amount,
                    ]);
                }

                public function rules()
                {
                    return [
                        'balance' => ['required', 'numeric'],
                    ];
                }
            }
            PHP,
        );

        $report = (new MoneyWriteCoverageService($tmp))->report();

        $this->assertSame(1, $report['scanned_files']);
        $this->assertSame([], $report['covered']);
        $this->assertSame(
            [
                'app/Services/Rogue/CreditService.php:9 balance',
                'app/Services/Rogue/CreditService.php:13 balance',
            ],
            $report['uncovered'],
        );
        $this->assertSame(
            ['app/Services/Rogue/CreditService.php:20'],
            $report['client_writable_balance'],
        );

        @unlink($tmp.'/app/Services/Rogue/CreditService.php');
        @rmdir($tmp.'/app/Services/Rogue');
        @rmdir($tmp.'/app/Services');
        @rmdir($tmp.'/app');
        @rmdir($tmp);
    }
}
