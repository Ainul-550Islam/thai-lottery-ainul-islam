<?php

declare(strict_types=1);

namespace Tests\Feature\Betting;

use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService;
use App\Services\Payment\StripeRefundRecoveryService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Finance\FinancialMasterTestCase;
use Tests\Support\RunsServerFinancialRaces;
use Tests\Support\StripeRefundWagerFixture;

final class StripeRefundWagerGuardServerTest extends FinancialMasterTestCase
{
    use RunsServerFinancialRaces;
    use StripeRefundWagerFixture;

    private function commands(array $f, int $other, string $action = 'refund_wager_context'): array
    {
        $base = ['action' => $action, 'user_id' => $f['user']->id, 'draw_id' => $f['draw']->id];

        return [$base + ['wallet_id' => $f['wallet']->id, 'key' => 'refund-waiter-one'],
            $base + ['wallet_id' => $other, 'key' => 'refund-waiter-two']];
    }

    public function test_two_distinct_wallet_buyers_wait_on_player_and_both_refuse_pending_exposure(): void
    {
        $this->requireServerRace();
        $f = $this->wagerRefundFixture();
        $other = $this->otherRefundWallet($f);
        $this->publishPendingRefund($f);
        $before = $this->refundWagerPaper();
        $rows = $this->serverRace($this->commands($f, $other->id), 'users', $f['user']->id);
        $this->assertSame(['refused', 'refused'], array_column($rows, 'outcome'), json_encode($rows));
        $this->assertSame(['bet_purchase_refund_exposure_pending', 'bet_purchase_refund_exposure_pending'], array_column($rows, 'code'));
        $this->assertSame($before, $this->refundWagerPaper());
    }

    public function test_newly_committed_lower_id_source_is_visible_after_both_old_rr_snapshots(): void
    {
        $this->requireServerRace();
        $f = $this->wagerRefundFixture();
        $other = $this->otherRefundWallet($f);
        $this->publishPendingRefund($f);
        // Controlled publication of exact previously authenticated custody, not a provider request or a fabricated proof.
        $intent = (array) DB::table(StripeRefundRecoveryService::INTENTS)->sole();
        $receipt = (array) DB::table(StripeRefundRecoveryService::RECEIPTS)->sole();
        DB::table(StripeRefundRecoveryService::RECEIPTS)->delete();
        DB::table(StripeRefundRecoveryService::INTENTS)->delete();
        $rows = $this->serverRace($this->commands($f, $other->id, 'refund_wager_snapshot'), 'users', $f['user']->id, [], null, null,
            function () use ($intent, $receipt): void {
                DB::table(StripeRefundRecoveryService::INTENTS)->insert($intent);
                DB::table(StripeRefundRecoveryService::RECEIPTS)->insert($receipt);
            });
        $this->assertSame(['refused', 'refused'], array_column($rows, 'outcome'), json_encode($rows));
        $this->assertSame(['bet_purchase_refund_exposure_pending', 'bet_purchase_refund_exposure_pending'], array_column($rows, 'code'));
        $this->assertSame('90.00', $f['wallet']->fresh()->balance);
        $this->assertSame('100.00', $other->fresh()->balance);
        $this->assertDatabaseCount('bets', 1);
        $this->assertDatabaseCount(StripeRefundRecoveryService::RECOVERIES, 0);
    }

    public function test_two_distinct_wallet_buyers_can_proceed_after_proved_financial_recovery(): void
    {
        $this->requireServerRace();
        $f = $this->wagerRefundFixture();
        $other = $this->otherRefundWallet($f);
        app(WalletService::class)->credit($f['wallet']->fresh(), Money::of('30', Currency::THB), FinancialTransactionType::Adjustment, 'refund-native-new-funds');
        $this->recoverRefund($f);
        $rows = $this->serverRace($this->commands($f, $other->id), 'users', $f['user']->id);
        $this->assertSame(['purchased', 'purchased'], array_column($rows, 'outcome'), json_encode($rows));
        $this->assertSame('10.00', $f['wallet']->fresh()->balance);
        $this->assertSame('90.00', $other->fresh()->balance);
        $this->assertDatabaseCount('bets', 3);
        $this->assertDatabaseCount(StripeRefundRecoveryService::RECOVERIES, 1);
    }
}
