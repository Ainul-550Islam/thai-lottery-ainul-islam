<?php

declare(strict_types=1);

namespace Tests\Support;

use App\DTOs\BetPurchaseContext;
use App\DTOs\BetPurchaseData;
use App\DTOs\Betting\BulkBetSelectionData;
use App\Enums\FinancialTransactionType;
use App\Enums\WalletType;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Wallet;
use App\Services\Betting\BetPurchaseService;
use App\Services\Betting\BetPurchaseValidator;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService;
use Illuminate\Support\Facades\DB;

trait StripeRefundWagerFixture
{
    use StripeRefundRecoveryFixture;

    protected function wagerRefundFixture(): array
    {
        $f = $this->refundFixture();
        $f['draw'] = $this->openBulkDrawForTest([new BulkBetSelectionData(marketKey: '3d_direct', number: '007', stake: '10.00')]);
        $f['data'] = new BetPurchaseData($f['user']->id, $f['draw']->id, '3d_direct', '007', '10.00', 'refund-wager-original');
        $f['purchase'] = app(BetPurchaseService::class)->purchase($f['data']);

        return $f;
    }

    protected function publishPendingRefund(array $f): void
    {
        try {
            $this->recoverRefund($f);
            $this->fail('Spent original funding must retain pending proved recovery.');
        } catch (InsufficientBalanceException) {
            $this->assertSame('90.00', $f['wallet']->fresh()->balance);
        }
    }

    protected function nextRefundBet(array $f, string $key = 'refund-wager-next'): BetPurchaseData
    {
        return new BetPurchaseData($f['user']->id, $f['draw']->id, '3d_direct', '007', '10.00', $key);
    }

    protected function otherRefundWallet(array $f): Wallet
    {
        $other = Wallet::factory()->create(['user_id' => $f['user']->id, 'currency' => $f['wallet']->currency, 'type' => WalletType::Bonus, 'balance' => '0.00']);
        app(WalletService::class)->credit($other, Money::of('100', $other->currency), FinancialTransactionType::Adjustment, 'refund-wager-other-wallet');

        return $other;
    }

    protected function refundContext(array $f, ?Wallet $wallet = null): BetPurchaseContext
    {
        $ctx = app(BetPurchaseValidator::class)->validate($this->nextRefundBet($f));

        return $wallet ? new BetPurchaseContext(...[...get_object_vars($ctx), 'wallet' => $wallet]) : $ctx;
    }

    protected function refundWagerPaper(): array
    {
        $out = $this->moneyPaper();
        foreach (['bets', 'bet_items', 'tickets', 'number_limits', 'bet_purchase_ownerships', 'bet_purchase_receipts', 'audit_logs',
            'stripe_refund_recovery_intents', 'stripe_refund_recovery_receipts', 'stripe_refund_recoveries'] as $table) {
            $out[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }

        return $out;
    }
}
