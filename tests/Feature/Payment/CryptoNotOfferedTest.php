<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Exceptions\FinancialException;
use App\Services\Payment\PaymentGatewayManager;

final class CryptoNotOfferedTest extends Batch01TestCase
{
    public function test_unsupported_crypto_is_not_capable_and_cannot_be_resolved_for_either_direction(): void
    {
        config([
            'payment.currency.default' => 'THB',
            'payment.country' => 'TH',
            'payment.gateways.crypto.enabled' => true,
            'payment.markets.crypto' => ['currencies' => ['USD'], 'countries' => ['TH']],
        ]);
        $gateways = app(PaymentGatewayManager::class);

        self::assertFalse($gateways->isDepositCapable(PaymentMethod::Crypto));
        self::assertFalse($gateways->isWithdrawalCapable(PaymentMethod::Crypto));
        try {
            $gateways->depositDriver(PaymentMethod::Crypto);
            self::fail('Crypto must remain unavailable even when its enabled flag is set.');
        } catch (FinancialException $exception) {
            self::assertSame('payment_gateway_not_ready', $exception->errorCode());
        }
        try {
            $gateways->withdrawalDriver(PaymentMethod::Crypto);
            self::fail('Crypto must never resolve as a withdrawal driver.');
        } catch (FinancialException) {
            self::assertTrue(true);
        }

        $crypto = $gateways->driver(PaymentMethod::Crypto);
        self::assertFalse($crypto->isEnabled());
        self::assertFalse($crypto->supportsDeposit());
        self::assertFalse($crypto->supportsWithdrawal());
        self::assertFalse($crypto->supportsWebhook());
        self::assertFalse($crypto->supportsCurrency(Currency::THB));
    }
}
