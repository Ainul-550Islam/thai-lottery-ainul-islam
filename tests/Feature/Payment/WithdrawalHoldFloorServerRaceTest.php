<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalCompletionService;
use App\Services\Finance\WithdrawalService;
use Illuminate\Support\Facades\DB;
use Tests\Support\RunsServerFinancialRaces;

/** Acknowledged local InnoDB only. Both independent children must wait on the parent's real wallet lock. */
final class WithdrawalHoldFloorServerRaceTest extends PaymentTestCase
{
    use RunsServerFinancialRaces;

    private function fixture(): array
    {
        $this->requireServerRace();
        $f = $this->createPlayer('1000.00');
        $row = app(WithdrawalService::class)->request($f['wallet'], Money::of('100.00', Currency::THB), PaymentMethod::BankTransfer);
        app(WithdrawalApprovalService::class)->approve($row);

        return $f + ['row' => $row->fresh()];
    }

    private function command(array $f, string $operation): array
    {
        return ['action' => 'wallet_floor', 'operation' => $operation, 'wallet_id' => $f['wallet']->id, 'withdrawal_id' => $f['row']->id];
    }

    private function completeAgainst(string $operation): void
    {
        $f = $this->fixture();
        $out = $this->serverRace([$this->command($f, $operation), $this->command($f, 'complete')], 'wallets', $f['wallet']->id);
        $this->assertSame(['refused', 'completed'], array_column($out, 'outcome'), json_encode($out));
        $this->assertSame('900.00', $f['wallet']->fresh()->balance);
        $this->assertSame('0.00', $f['wallet']->fresh()->locked_balance);
        $this->assertSame('consumed', DB::table('withdrawal_reservations')->sole()->state);
        app(WithdrawalCompletionService::class)->verifySettled($f['row']->fresh());
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('ledger_entries', 2);
    }

    public function test_generic_unlock_cannot_race_the_original_withdrawal_completion(): void
    {
        $this->completeAgainst('unlock');
    }

    public function test_generic_consume_cannot_race_the_original_withdrawal_completion(): void
    {
        $this->completeAgainst('consume');
    }

    public function test_unprotected_release_and_owned_completion_both_preserve_the_floor(): void
    {
        $f = $this->fixture();
        app(WalletService::class)->lockFunds($f['wallet'], Money::of('100.00', Currency::THB));
        $out = $this->serverRace([$this->command($f, 'unlock'), $this->command($f, 'complete')], 'wallets', $f['wallet']->id);
        $this->assertSame(['completed', 'completed'], array_column($out, 'outcome'), json_encode($out));
        $this->assertSame('900.00', $f['wallet']->fresh()->balance);
        $this->assertSame('0.00', $f['wallet']->fresh()->locked_balance);
        app(WithdrawalCompletionService::class)->verifySettled($f['row']->fresh());
        $this->assertDatabaseCount('financial_transactions', 1);
    }

    public function test_generic_hold_and_credit_cannot_race_to_camouflage_existing_deficit(): void
    {
        $f = $this->fixture();
        $other = app(WithdrawalService::class)->request($f['wallet'], Money::of('100.00', Currency::THB), PaymentMethod::BankTransfer);
        app(WithdrawalApprovalService::class)->approve($other);
        DB::table('wallets')->where('id', $f['wallet']->id)->update(['locked_balance' => '100.00']);
        $out = $this->serverRace([$this->command($f, 'hold'), $this->command($f, 'credit')], 'wallets', $f['wallet']->id);
        $this->assertSame(['refused', 'refused'], array_column($out, 'outcome'), json_encode($out));
        $this->assertSame(['wallet_withdrawal_floor_conflict', 'wallet_withdrawal_floor_conflict'], array_column($out, 'code'), json_encode($out));
        $this->assertSame('1000.00', $f['wallet']->fresh()->balance);
        $this->assertSame('100.00', $f['wallet']->fresh()->locked_balance);
        $this->assertSame(2, DB::table('withdrawal_reservations')->where('state', 'active')->count());
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_legitimate_available_spend_and_owned_completion_do_not_spend_the_hold_twice(): void
    {
        $f = $this->fixture();
        $out = $this->serverRace([$this->command($f, 'debit'), $this->command($f, 'complete')], 'wallets', $f['wallet']->id);
        $this->assertSame(['completed', 'completed'], array_column($out, 'outcome'), json_encode($out));
        $this->assertSame('0.00', $f['wallet']->fresh()->balance);
        $this->assertSame('0.00', $f['wallet']->fresh()->locked_balance);
        app(WithdrawalCompletionService::class)->verifySettled($f['row']->fresh());
        $this->assertDatabaseCount('financial_transactions', 2);
        $this->assertDatabaseCount('ledger_entries', 4);
    }

    public function test_preexisting_repeatable_read_snapshots_cannot_hide_a_newly_committed_owner(): void
    {
        $this->requireServerRace();
        $f = $this->createPlayer('1000.00');
        $row = app(WithdrawalService::class)->request($f['wallet'], Money::of('100.00', Currency::THB), PaymentMethod::BankTransfer);
        $f['row'] = $row;
        $out = $this->serverRace([$this->command($f, 'snapshot_unlock'), $this->command($f, 'snapshot_unlock')],
            'wallets', $f['wallet']->id, [], fn () => app(WithdrawalApprovalService::class)->approve($row));
        $this->assertSame(['refused', 'refused'], array_column($out, 'outcome'), json_encode($out));
        $this->assertSame(['wallet_withdrawal_floor_conflict', 'wallet_withdrawal_floor_conflict'], array_column($out, 'code'), json_encode($out));
        $this->assertSame('100.00', $f['wallet']->fresh()->locked_balance);
        $this->assertSame('1000.00', $f['wallet']->fresh()->balance);
        $this->assertSame('active', DB::table('withdrawal_reservations')->sole()->state);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_duplicate_owned_completion_closes_one_reservation_and_posts_one_debit(): void
    {
        $f = $this->fixture();
        $out = $this->serverRace([$this->command($f, 'complete'), $this->command($f, 'complete')], 'wallets', $f['wallet']->id);
        $this->assertSame(['completed', 'completed'], array_column($out, 'outcome'), json_encode($out));
        $this->assertSame('900.00', $f['wallet']->fresh()->balance);
        $this->assertSame('0.00', $f['wallet']->fresh()->locked_balance);
        $this->assertSame('consumed', DB::table('withdrawal_reservations')->sole()->state);
        app(WithdrawalCompletionService::class)->verifySettled($f['row']->fresh());
        $this->assertDatabaseCount('withdrawal_reservations', 1);
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('ledger_entries', 2);
    }
}
