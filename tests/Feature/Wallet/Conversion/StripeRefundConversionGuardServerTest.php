<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet\Conversion;

use App\Enums\Currency;
use App\Enums\WalletConversionDirection;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService;
use App\Services\Payment\StripeRefundRecoveryService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Finance\FinancialMasterTestCase;
use Tests\Support\RunsServerFinancialRaces;
use Tests\Support\StripeRefundConversionFixture;

final class StripeRefundConversionGuardServerTest extends FinancialMasterTestCase
{
    use RunsServerFinancialRaces;
    use StripeRefundConversionFixture;

    private function commands(array $f, string $action = 'refund_conversion'): array
    {
        $commands = [];
        foreach ([WalletConversionDirection::CashToWin, WalletConversionDirection::WinToCash] as $index => $direction) {
            $key = 'refund-conversion-waiter-key-'.$index;
            $request = $this->quotedConversionRequest($f, $key, $direction);
            $commands[] = ['action' => $action, 'wallet_id' => $index === 0 ? $f['wallet']->id : $f['win']->id,
                'request' => ['direction' => $direction->value, 'amount' => '10.00', 'currency' => 'THB',
                    'channel' => 'api', 'user_id' => $f['user']->id, 'idempotency_key' => $key, 'quote_reference' => $request->quoteReference]];
        }

        return $commands;
    }

    public function test_opposite_direction_writers_wait_on_owner_and_both_refuse_pending_source(): void
    {
        $this->requireServerRace();
        $f = $this->conversionRefundFixture();
        $commands = $this->commands($f);
        $this->publishConversionRefund($f);
        $before = $this->conversionPaper();
        $rows = $this->serverRace($commands, 'users', $f['user']->id);
        $this->assertSame(['refused', 'refused'], array_column($rows, 'outcome'), json_encode($rows));
        $this->assertSame(['conversion_refund_exposure_pending', 'conversion_refund_exposure_pending'], array_column($rows, 'code'));
        $this->assertSame($before, $this->conversionPaper());
    }

    public function test_two_old_empty_rr_snapshots_observe_new_original_custody_before_money_moves(): void
    {
        $this->requireServerRace();
        $f = $this->conversionRefundFixture();
        $commands = $this->commands($f, 'refund_conversion_snapshot');
        $this->publishConversionRefund($f);
        $intent = (array) DB::table(StripeRefundRecoveryService::INTENTS)->sole();
        $receipt = (array) DB::table(StripeRefundRecoveryService::RECEIPTS)->sole();
        DB::table(StripeRefundRecoveryService::RECEIPTS)->delete();
        DB::table(StripeRefundRecoveryService::INTENTS)->delete();
        $before = $this->moneyPaper();
        $rows = $this->serverRace($commands, 'users', $f['user']->id, [], null, null, function () use ($intent, $receipt): void {
            DB::table(StripeRefundRecoveryService::INTENTS)->insert($intent);
            DB::table(StripeRefundRecoveryService::RECEIPTS)->insert($receipt);
        });
        $this->assertSame(['refused', 'refused'], array_column($rows, 'outcome'), json_encode($rows));
        $this->assertSame(['conversion_refund_exposure_pending', 'conversion_refund_exposure_pending'], array_column($rows, 'code'));
        $this->assertSame($before, $this->moneyPaper());
        $this->assertDatabaseCount('wallet_conversions', 0);
        $this->assertDatabaseCount(StripeRefundRecoveryService::INTENTS, 1);
    }

    public function test_unproved_receipt_refuses_both_writers_without_quote_consumption(): void
    {
        $this->requireServerRace();
        $f = $this->conversionRefundFixture();
        $commands = $this->commands($f);
        $this->publishConversionRefund($f);
        DB::table(StripeRefundRecoveryService::RECEIPTS)->update(['response_hash' => str_repeat('0', 64)]);
        $before = $this->conversionPaper();
        $rows = $this->serverRace($commands, 'users', $f['user']->id);
        $this->assertSame(['refused', 'refused'], array_column($rows, 'outcome'), json_encode($rows));
        $this->assertSame(['conversion_refund_exposure_unproven', 'conversion_refund_exposure_unproven'], array_column($rows, 'code'));
        $this->assertSame($before, $this->conversionPaper());
    }

    public function test_proved_recovery_allows_two_new_balanced_conversions_without_recovery_replay(): void
    {
        $this->requireServerRace();
        $f = $this->conversionRefundFixture();
        app(WalletService::class)->credit($f['wallet']->fresh(), Money::of('30', Currency::THB), idempotencyKey: 'native-conversion-recovery-funds');
        $this->recoverRefund($f);
        $commands = $this->commands($f);
        $recoveries = DB::table(StripeRefundRecoveryService::RECOVERIES)->get()->toJson();
        $rows = $this->serverRace($commands, 'users', $f['user']->id);
        $this->assertSame(['completed', 'completed'], array_column($rows, 'outcome'), json_encode($rows));
        $this->assertSame('19.90', $f['wallet']->fresh()->balance);
        $this->assertSame('99.90', $f['win']->fresh()->balance);
        $this->assertDatabaseCount('wallet_conversions', 2);
        $this->assertSame($recoveries, DB::table(StripeRefundRecoveryService::RECOVERIES)->get()->toJson());
    }
}
