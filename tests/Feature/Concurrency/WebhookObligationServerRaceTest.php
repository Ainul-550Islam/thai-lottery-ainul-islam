<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Enums\WithdrawalStatus;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Services\Finance\Money;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalService;
use Tests\Feature\Payment\PaymentTestCase;
use Tests\Support\RunsServerFinancialRaces;

/** Real independent callback processes must wait on the same owned wallet lock. */
final class WebhookObligationServerRaceTest extends PaymentTestCase
{
    use RunsServerFinancialRaces;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireServerRace();
    }

    private function command($row, bool $withdrawal = false, bool $success = true, string $gateway = 'bkash'): array
    {
        return ['action' => 'webhook', 'webhook' => ['gateway' => $gateway, 'event' => 'race-'.bin2hex(random_bytes(10)),
            'type' => ($withdrawal ? 'withdrawal.' : 'deposit.').($success ? 'success' : 'failed'),
            'provider' => 'synthetic-race-provider', 'internal' => $row->reference_number,
            'amount' => '400.00', 'currency' => 'BDT', 'success' => $success]];
    }

    private function withdrawal(): array
    {
        $player = $this->createPlayer('1000.00', Currency::BDT);
        $row = app(WithdrawalService::class)->request($player['wallet'], Money::of('400.00', Currency::BDT), PaymentMethod::Bkash,
            options: ['payout_details' => ['phone' => '01711111111']]);
        app(WithdrawalApprovalService::class)->approve($row);

        return [$row->fresh(), $player['wallet']];
    }

    public function test_concurrent_deposit_success_credits_once(): void
    {
        $player = $this->createPlayer('100.00', Currency::BDT);
        $row = $this->createPendingDeposit($player['wallet'], '400.00', PaymentMethod::Bkash);
        $out = $this->serverRace([$this->command($row), $this->command($row)], 'wallets', (int) $player['wallet']->id);
        $this->assertSame([true, true], array_column($out, 'success'), json_encode($out));
        $this->assertEqualsCanonicalizing(['credited', 'replayed'], array_column($out, 'outcome'));
        $this->assertSame('500.00', (string) $player['wallet']->fresh()->balance);
        $this->assertSame(1, FinancialTransaction::query()->count());
        $this->assertSame(2, LedgerEntry::query()->count());
        $this->assertSame(1, Payment::query()->count());
    }

    public function test_concurrent_withdrawal_success_debits_once(): void
    {
        [$row, $wallet] = $this->withdrawal();
        $out = $this->serverRace([$this->command($row, true), $this->command($row, true)], 'wallets', (int) $wallet->id);
        $this->assertSame([true, true], array_column($out, 'success'), json_encode($out));
        $this->assertEqualsCanonicalizing(['debited', 'ignored'], array_column($out, 'outcome'));
        $this->assertSame('600.00', (string) $wallet->fresh()->balance);
        $this->assertSame('0.00', (string) $wallet->fresh()->locked_balance);
        $this->assertSame(WithdrawalStatus::Completed, $row->fresh()->status);
        $this->assertSame(1, FinancialTransaction::query()->count());
        $this->assertSame(2, LedgerEntry::query()->count());
    }

    public function test_concurrent_withdrawal_success_and_failure_preserve_one_terminal_state(): void
    {
        [$row, $wallet] = $this->withdrawal();
        $out = $this->serverRace([$this->command($row, true), $this->command($row, true, false)], 'wallets', (int) $wallet->id);
        $this->assertCount(2, $out);
        $this->assertNotContains('refused', array_column($out, 'outcome'), json_encode($out));
        $completed = $row->fresh()->status === WithdrawalStatus::Completed;
        $this->assertSame($completed ? WithdrawalStatus::Completed : WithdrawalStatus::Failed, $row->fresh()->status);
        $this->assertSame($completed ? '600.00' : '1000.00', (string) $wallet->fresh()->balance);
        $this->assertSame('0.00', (string) $wallet->fresh()->locked_balance);
        $this->assertSame($completed ? 1 : 0, FinancialTransaction::query()->count());
        $this->assertSame($completed ? 2 : 0, LedgerEntry::query()->count());
    }

    public function test_concurrent_foreign_provider_cannot_race_a_valid_credit(): void
    {
        $player = $this->createPlayer('100.00', Currency::BDT);
        $row = $this->createPendingDeposit($player['wallet'], '400.00', PaymentMethod::Bkash);
        $out = $this->serverRace([$this->command($row), $this->command($row, gateway: 'stripe')], 'wallets', (int) $player['wallet']->id);
        $this->assertTrue($out[0]['success']);
        $this->assertFalse($out[1]['success']);
        $this->assertSame('500.00', (string) $player['wallet']->fresh()->balance);
        $this->assertSame(1, FinancialTransaction::query()->count());
        $this->assertSame(2, LedgerEntry::query()->count());
        $this->assertSame('bkash', Payment::query()->sole()->gateway);
    }
}
