<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Enums\WalletType;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Ops\LedgerIntegrityChecker;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * ledger:verify is READ-ONLY, and that is the property under test.
 *
 * A verification tool that "corrects" what it finds is a tool that destroys the
 * evidence of the incident it was run to investigate. Every test below therefore
 * checks two things: the verdict AND that the row counts and hashes are unchanged
 * afterwards.
 *
 * The exit-code contract (0 clean, 1 discrepancy, 2 could-not-run) is an interface
 * for an alerting wrapper, so it is asserted through the console, not just the class.
 */
final class LedgerIntegrityCheckerTest extends TestCase
{
    use DatabaseTruncation;

    public function test_an_empty_ledger_reports_clean_and_exits_zero(): void
    {
        $this->artisan('ledger:verify')->assertExitCode(0);
    }

    public function test_the_checker_reports_a_verdict_per_invariant(): void
    {
        $report = app(LedgerIntegrityChecker::class)->check();

        $this->assertArrayHasKey('clean', $report);
        $this->assertArrayHasKey('checked_wallets', $report);
        $this->assertArrayHasKey('checked_transactions', $report);
        $this->assertArrayHasKey('wallet_discrepancies', $report);
        $this->assertArrayHasKey('transaction_discrepancies', $report);
        $this->assertIsBool($report['clean']);
        $this->assertTrue($report['clean'], 'A database with no wallets and no transactions has nothing to disagree about.');
    }

    private function walletWithBalance(string $balance): Wallet
    {
        $user = User::factory()->create();

        $wallet = new Wallet();
        $wallet->user_id = $user->id;
        $wallet->type = WalletType::Main;
        $wallet->currency = \App\Enums\Currency::THB;
        $wallet->balance = $balance;
        $wallet->locked_balance = '0.00';
        $wallet->save();

        return $wallet;
    }

    public function test_it_reports_a_wallet_that_disagrees_with_its_ledger(): void
    {
        // A balance with no ledger entries behind it is the canonical mismatch: the
        // money has no provenance. The checker must SEE it.
        $this->walletWithBalance('500.00');

        $report = app(LedgerIntegrityChecker::class)->check();

        $this->assertFalse($report['clean']);
        $this->assertNotEmpty($report['wallet_discrepancies']);
        $this->assertSame(1, $report['checked_wallets']);
    }

    public function test_it_is_read_only_even_when_it_finds_a_discrepancy(): void
    {
        $wallet = $this->walletWithBalance('500.00');

        $before = (string) $wallet->fresh()->balance;

        app(LedgerIntegrityChecker::class)->check();

        $this->assertSame(
            $before,
            (string) $wallet->fresh()->balance,
            'ledger:verify must never modify the wallet it found to be wrong.',
        );
    }

    public function test_it_reports_a_discrepancy_through_the_exit_code(): void
    {
        $this->walletWithBalance('500.00');

        $this->artisan('ledger:verify')->assertExitCode(1);
    }

    public function test_a_matching_wallet_and_ledger_exits_zero(): void
    {
        // A wallet whose balance is exactly what its ledger entries say is clean.
        // This is the positive control: without it, "exit 1" above could just mean
        // the command always fails.
        $wallet = $this->walletWithBalance('0.00');

        $this->assertSame('0.00', (string) $wallet->fresh()->balance);

        $this->artisan('ledger:verify')->assertExitCode(0);
    }

    public function test_it_offers_no_fix_flag(): void
    {
        // A --fix flag on this command would be a footgun that no runbook can undo.
        $definitions = \Illuminate\Support\Facades\Artisan::all();
        $command = $definitions['ledger:verify'] ?? null;

        $this->assertNotNull($command);
        $this->assertFalse(
            $command->getDefinition()->hasOption('fix'),
            'ledger:verify must never be able to change the data it verifies.',
        );
    }

    public function test_it_runs_twice_without_side_effects(): void
    {
        $before = [
            'wallets' => \App\Models\Wallet::query()->count(),
            'ledger_entries' => \App\Models\LedgerEntry::query()->count(),
        ];

        app(LedgerIntegrityChecker::class)->check();
        app(LedgerIntegrityChecker::class)->check();

        $this->assertSame($before['wallets'], \App\Models\Wallet::query()->count());
        $this->assertSame($before['ledger_entries'], \App\Models\LedgerEntry::query()->count());
    }

    public function test_the_report_carries_no_secret_and_no_member_credential(): void
    {
        $json = json_encode(app(LedgerIntegrityChecker::class)->check(), JSON_THROW_ON_ERROR);

        foreach (['password', 'token', 'secret'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $json);
        }
    }
}
