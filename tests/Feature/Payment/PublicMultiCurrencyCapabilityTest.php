<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Services\Payments\PublicPaymentMethodsService;

final class PublicMultiCurrencyCapabilityTest extends PaymentTestCase
{
    private function market(string $default): void
    {
        config([
            'payment.currency.default' => $default, 'payment.country' => 'US',
            'payment.deposit.enabled' => true, 'payment.deposit.allowed_methods' => ['stripe'],
            'payment.markets.stripe' => ['currencies' => ['USD'], 'countries' => ['US']],
            'payment.gateways.stripe.enabled' => true,
            'payment.gateways.stripe.supports_deposit' => true,
            'payment.gateways.stripe.supported_currencies' => ['THB', 'USD'],
            'payment.gateways.stripe.key' => 'pk_test_fixture',
            'payment.gateways.stripe.secret' => 'sk_test_fixture',
            'payment.gateways.stripe.webhook_secret' => 'whsec_test_fixture',
        ]);
    }

    public function test_public_currencies_do_not_claim_an_unavailable_merchant_market(): void
    {
        $this->market('USD');
        $result = app(PublicPaymentMethodsService::class)->publicMethods();
        self::assertSame('AVAILABLE', $result['status']);
        self::assertSame(['stripe'], array_column($result['methods'], 'code'));
        self::assertSame(['USD'], $result['methods'][0]['currencies']);
    }

    public function test_an_eligible_currency_is_not_hidden_by_a_different_default(): void
    {
        $this->market('THB');
        $result = app(PublicPaymentMethodsService::class)->publicMethods();
        self::assertSame('AVAILABLE', $result['status']);
        self::assertSame(['stripe'], array_column($result['methods'], 'code'));
        self::assertSame(['USD'], $result['methods'][0]['currencies']);
    }
}
