<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\TransactionStatus;
use App\Models\FinancialTransaction;
use App\Services\Finance\FinancialReversalService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService;
use Tests\Support\RunsServerFinancialRaces;

final class ReversalIntegrityServerRaceTest extends PaymentTestCase
{
    use RunsServerFinancialRaces;

    private function fixture(bool $two = false): array
    {
        $this->requireServerRace();
        $f = $this->createPlayer('0.00');
        $wallets = app(WalletService::class);
        $f['first'] = $wallets->credit($f['wallet'], Money::of('100.00', Currency::THB), FinancialTransactionType::Deposit, 'synthetic-reversal-first');
        if ($two) {
            $f['second'] = $wallets->credit($f['wallet'], Money::of('100.00', Currency::THB), FinancialTransactionType::Deposit, 'synthetic-reversal-second');
        }

        return $f;
    }

    private function command(FinancialTransaction $tx, array $extra = []): array
    {
        return $extra + ['action' => 'compensate', 'transaction_id' => $tx->id, 'wallet_id' => $tx->wallet_id];
    }

    public function test_duplicate_original_is_compensated_once_with_the_same_proved_result(): void
    {
        $f = $this->fixture();
        $out = $this->serverRace([$this->command($f['first']), $this->command($f['first'])], 'wallets', $f['wallet']->id);
        $this->assertSame(['reversed', 'reversed'], array_column($out, 'outcome'), json_encode($out));
        $this->assertSame($out[0]['transaction_id'], $out[1]['transaction_id']);
        $this->assertSame('0.00', $f['wallet']->fresh()->balance);
        $this->assertDatabaseCount('financial_transactions', 2);
        $this->assertDatabaseCount('ledger_entries', 4);
        $this->assertSame($out[0]['transaction_id'], app(FinancialReversalService::class)->existingReversalFor($f['first'])->id);
    }

    public function test_distinct_originals_never_coalesce_on_the_same_wallet_and_amount(): void
    {
        $f = $this->fixture(true);
        $out = $this->serverRace([$this->command($f['first']), $this->command($f['second'])], 'wallets', $f['wallet']->id);
        $this->assertSame(['reversed', 'reversed'], array_column($out, 'outcome'), json_encode($out));
        $this->assertNotSame($out[0]['transaction_id'], $out[1]['transaction_id']);
        $this->assertSame('0.00', $f['wallet']->fresh()->balance);
        $this->assertDatabaseCount('financial_transactions', 4);
        $this->assertDatabaseCount('ledger_entries', 8);
    }

    public function test_same_caller_key_cannot_replay_a_different_original_under_contention(): void
    {
        $f = $this->fixture(true);
        $key = app(FinancialReversalService::class)->reversalKeyFor($f['first']);
        $out = $this->serverRace([$this->command($f['first'], ['key' => $key]), $this->command($f['second'], ['key' => $key])], 'wallets', $f['wallet']->id);
        $this->assertSame(['reversed', 'refused'], array_column($out, 'outcome'), json_encode($out));
        $this->assertSame('reversal_noncanonical_key', $out[1]['code']);
        $this->assertSame(TransactionStatus::Completed, $f['second']->fresh()->status);
        $this->assertSame('100.00', $f['wallet']->fresh()->balance);
        $this->assertDatabaseCount('financial_transactions', 3);
        $this->assertDatabaseCount('ledger_entries', 6);
    }

    public function test_spend_and_compensation_cannot_both_take_the_last_available_money(): void
    {
        $f = $this->fixture();
        $out = $this->serverRace([$this->command($f['first']), ['action' => 'compensate_spend', 'wallet_id' => $f['wallet']->id]], 'wallets', $f['wallet']->id);
        $outcomes = array_column($out, 'outcome');
        $this->assertSame(1, count(array_filter($outcomes, fn ($outcome): bool => $outcome === 'refused')), json_encode($out));
        $this->assertSame(1, count(array_filter($outcomes, fn ($outcome): bool => in_array($outcome, ['reversed', 'spent'], true))));
        $this->assertSame('0.00', $f['wallet']->fresh()->balance);
        $this->assertDatabaseCount('financial_transactions', 2);
        $this->assertDatabaseCount('ledger_entries', 4);
    }

    public function test_old_snapshots_read_the_newly_committed_canonical_compensation_for_replay(): void
    {
        $f = $this->fixture();
        $out = $this->serverRace([$this->command($f['first'], ['snapshot' => true]), $this->command($f['first'], ['snapshot' => true])], 'wallets', $f['wallet']->id,
            afterWalletLock: fn () => app(FinancialReversalService::class)->reverse($f['first']));
        $this->assertSame(['reversed', 'reversed'], array_column($out, 'outcome'), json_encode($out));
        $this->assertSame($out[0]['transaction_id'], $out[1]['transaction_id']);
        $this->assertSame('0.00', $f['wallet']->fresh()->balance);
        $this->assertDatabaseCount('financial_transactions', 2);
        $this->assertDatabaseCount('ledger_entries', 4);
    }
}
