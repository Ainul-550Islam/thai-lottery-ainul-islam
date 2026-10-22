<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService;
use App\Services\Finance\WithdrawalService;
use App\Services\Payment\StripeRefundRecoveryService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Finance\FinancialMasterTestCase;
use Tests\Support\RunsServerFinancialRaces;
use Tests\Support\StripeRefundWagerFixture;

final class StripeRefundWithdrawalGuardServerTest extends FinancialMasterTestCase
{
    use RunsServerFinancialRaces;
    use StripeRefundWagerFixture;

    private function commands(array $f, int $other, string $action = 'refund_withdrawal_request'): array
    {
        return [['action' => $action, 'wallet_id' => $f['wallet']->id, 'key' => 'refund-withdraw-waiter-one'],
            ['action' => $action, 'wallet_id' => $other, 'key' => 'refund-withdraw-waiter-two']];
    }

    private function paper(): array
    {
        $out = $this->refundWagerPaper();
        foreach (['withdrawals', 'withdrawal_reservations'] as $table) {
            $out[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $out;
    }

    public function test_distinct_wallet_requesters_both_wait_on_player_and_refuse_pending_source(): void
    {
        $this->requireServerRace();
        $f = $this->wagerRefundFixture();
        $other = $this->otherRefundWallet($f);
        $this->publishPendingRefund($f);
        $before = $this->paper();
        $rows = $this->serverRace($this->commands($f, $other->id), 'users', $f['user']->id);
        $this->assertSame(['refused', 'refused'], array_column($rows, 'outcome'), json_encode($rows));
        $this->assertSame(['withdrawal_refund_exposure_pending', 'withdrawal_refund_exposure_pending'], array_column($rows, 'code'));
        $this->assertSame($before, $this->paper());
    }

    public function test_actual_requests_observe_new_lower_id_custody_after_two_old_empty_rr_snapshots(): void
    {
        $this->requireServerRace();
        $f = $this->wagerRefundFixture();
        $other = $this->otherRefundWallet($f);
        $this->publishPendingRefund($f);
        $intent = (array) DB::table(StripeRefundRecoveryService::INTENTS)->sole();
        $receipt = (array) DB::table(StripeRefundRecoveryService::RECEIPTS)->sole();
        DB::table(StripeRefundRecoveryService::RECEIPTS)->delete();
        DB::table(StripeRefundRecoveryService::INTENTS)->delete();
        $rows = $this->serverRace($this->commands($f, $other->id, 'refund_withdrawal_snapshot'), 'users', $f['user']->id, [], null, null,
            function () use ($intent, $receipt): void {
                DB::table(StripeRefundRecoveryService::INTENTS)->insert($intent);
                DB::table(StripeRefundRecoveryService::RECEIPTS)->insert($receipt);
            });
        $this->assertSame(['refused', 'refused'], array_column($rows, 'outcome'), json_encode($rows));
        $this->assertSame(['withdrawal_refund_exposure_pending', 'withdrawal_refund_exposure_pending'], array_column($rows, 'code'));
        $this->assertDatabaseCount('withdrawals', 0);
        $this->assertDatabaseCount('withdrawal_reservations', 0);
        $this->assertSame('90.00', $f['wallet']->fresh()->balance);
        $this->assertSame('100.00', $other->fresh()->balance);
    }

    public function test_existing_unapproved_requests_cannot_reserve_on_either_wallet_after_publication(): void
    {
        $this->requireServerRace();
        config(['finance.withdrawal.min' => '1.00']);
        $f = $this->wagerRefundFixture();
        $other = $this->otherRefundWallet($f);
        $a = app(WithdrawalService::class)->request($f['wallet'], Money::of('10', Currency::THB), PaymentMethod::Manual, 'old-before-source-one');
        $b = app(WithdrawalService::class)->request($other, Money::of('10', Currency::THB), PaymentMethod::Manual, 'old-before-source-two');
        $this->publishPendingRefund($f);
        $before = $this->paper();
        $commands = $this->commands($f, $other->id, 'refund_withdrawal_approve');
        $commands[0]['withdrawal_id'] = $a->id;
        $commands[1]['withdrawal_id'] = $b->id;
        $rows = $this->serverRace($commands, 'users', $f['user']->id);
        $this->assertSame(['refused', 'refused'], array_column($rows, 'outcome'), json_encode($rows));
        $this->assertSame(['withdrawal_refund_exposure_pending', 'withdrawal_refund_exposure_pending'], array_column($rows, 'code'));
        $this->assertSame($before, $this->paper());
    }

    public function test_both_requesters_can_proceed_after_actual_proved_recovery_without_another_debit(): void
    {
        $this->requireServerRace();
        $f = $this->wagerRefundFixture();
        $other = $this->otherRefundWallet($f);
        app(WalletService::class)->credit($f['wallet']->fresh(), Money::of('30', Currency::THB), FinancialTransactionType::Adjustment, 'native-withdraw-funds');
        $this->recoverRefund($f);
        $before = $this->moneyPaper();
        $rows = $this->serverRace($this->commands($f, $other->id), 'users', $f['user']->id);
        $this->assertSame(['requested', 'requested'], array_column($rows, 'outcome'), json_encode($rows));
        $this->assertSame($before, $this->moneyPaper());
        $this->assertDatabaseCount('withdrawals', 2);
        $this->assertDatabaseCount('withdrawal_reservations', 0);
    }
}
