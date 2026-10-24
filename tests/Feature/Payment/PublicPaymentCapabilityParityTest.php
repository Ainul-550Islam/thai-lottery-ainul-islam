<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\PaymentMethod;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payments\PublicPaymentMethodsService;
use Illuminate\Support\Facades\Config;

/**
 * P1-23: Parity between public payment capability claims and gateway manager execution.
 */
final class PublicPaymentCapabilityParityTest extends PaymentTestCase
{
    public function test_globally_disabled_deposits_fail_closed(): void
    {
        Config::set('payment.deposit.enabled', false);

        $service = app(PublicPaymentMethodsService::class);
        $result = $service->publicMethods();

        $this->assertSame('NOT_CONFIGURED', $result['status']);
        $this->assertEmpty($result['methods']);
    }

    public function test_unconfigured_gateway_is_not_advertised(): void
    {
        Config::set('payment.deposit.enabled', true);
        Config::set('payment.deposit.allowed_methods', ['stripe', 'bkash']);
        Config::set('payment.gateways.stripe.enabled', false);
        Config::set('payment.gateways.bkash.enabled', false);

        $service = app(PublicPaymentMethodsService::class);
        $result = $service->publicMethods();

        $this->assertSame('NOT_CONFIGURED', $result['status']);
        $this->assertEmpty($result['methods']);
    }

    public function test_bank_transfer_requires_valid_settlement_instructions(): void
    {
        Config::set('payment.deposit.enabled', true);
        Config::set('payment.deposit.allowed_methods', ['bank_transfer']);
        Config::set('payment.gateways.bank_transfer.enabled', true);
        Config::set('payment.gateways.bank_transfer.settlement', [
            'bank_name' => '',
            'account_number' => '',
            'account_name' => '',
        ]);

        $service = app(PublicPaymentMethodsService::class);
        $result = $service->publicMethods();

        // Missing settlement instructions => not available
        $this->assertSame('NOT_CONFIGURED', $result['status']);
        $this->assertEmpty($result['methods']);

        // Set valid settlement instructions
        Config::set('payment.gateways.bank_transfer.settlement', [
            'bank_name' => 'Bangkok Bank',
            'account_number' => '444-5-66677-8',
            'account_name' => 'Operator Settlement Ltd',
        ]);

        $resultValid = $service->publicMethods();
        $this->assertSame('AVAILABLE', $resultValid['status']);
        $this->assertCount(1, $resultValid['methods']);
        $this->assertSame('bank_transfer', $resultValid['methods'][0]['code']);
    }

    public function test_all_advertised_methods_match_gateway_manager_deposit_capability(): void
    {
        Config::set('payment.deposit.enabled', true);
        Config::set('payment.deposit.allowed_methods', ['stripe', 'bank_transfer']);
        Config::set('payment.gateways.stripe.enabled', true);
        Config::set('payment.gateways.stripe.secret', self::STRIPE_SECRET);
        Config::set('payment.gateways.bank_transfer.enabled', true);
        Config::set('payment.gateways.bank_transfer.settlement', [
            'bank_name' => 'Kasikornbank',
            'account_number' => '987-6-54321-0',
            'account_name' => 'Thai Lottery Settlement',
        ]);

        $service = app(PublicPaymentMethodsService::class);
        $manager = app(PaymentGatewayManager::class);

        $result = $service->publicMethods();
        $this->assertSame('AVAILABLE', $result['status']);

        foreach ($result['methods'] as $methodInfo) {
            $enum = PaymentMethod::from($methodInfo['code']);
            $this->assertTrue($manager->isDepositCapable($enum), "Method {$methodInfo['code']} must be deposit-capable in gateway manager.");
        }
    }
}
