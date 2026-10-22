<?php

declare(strict_types=1);

namespace Tests\Support;

use App\DTOs\Wallet\ConversionRequestData;
use App\Enums\Currency;
use App\Enums\WalletConversionChannel;
use App\Enums\WalletConversionDirection;
use App\Enums\WalletType;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Wallet;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService;
use App\Services\Wallet\Conversion\WalletConversionService;
use Illuminate\Support\Facades\DB;

trait StripeRefundConversionFixture
{
    use StripeRefundWagerFixture;

    protected function conversionRefundFixture(): array
    {
        $f = $this->wagerRefundFixture();
        $this->verifyUserKycForTest($f['user']);
        $f['win'] = Wallet::factory()->create(['user_id' => $f['user']->id, 'type' => WalletType::Win]);
        app(WalletService::class)->credit($f['win'], Money::of('100', Currency::THB), idempotencyKey: 'refund-convert-win-funding');

        return $f;
    }

    protected function publishConversionRefund(array $f): void
    {
        $before = $f['wallet']->fresh()->balance;
        try {
            $this->recoverRefund($f);
            $this->fail('Spent capture must retain proved pending custody.');
        } catch (InsufficientBalanceException) {
            $this->assertSame($before, $f['wallet']->fresh()->balance);
        }
    }

    protected function conversionRequest(array $f, string $key = 'refund-conversion-new-key', WalletConversionDirection $direction = WalletConversionDirection::CashToWin, ?string $quote = null, Currency $currency = Currency::THB): ConversionRequestData
    {
        return new ConversionRequestData($direction, '10.00', $currency, WalletConversionChannel::Api, (int) $f['user']->id, $key, $quote);
    }

    protected function quotedConversionRequest(array $f, string $key = 'refund-conversion-new-key', WalletConversionDirection $direction = WalletConversionDirection::CashToWin, Currency $currency = Currency::THB): ConversionRequestData
    {
        $quote = app(WalletConversionService::class)->quote($this->conversionRequest($f, $key, $direction, currency: $currency));

        return $this->conversionRequest($f, $key, $direction, $quote->reference, $currency);
    }

    protected function conversionPaper(): array
    {
        $out = $this->moneyPaper();
        foreach (['wallet_conversions', 'wallet_conversion_quotes', 'stripe_refund_recovery_intents', 'stripe_refund_recovery_receipts', 'stripe_refund_recoveries'] as $table) {
            $out[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $out;
    }
}
