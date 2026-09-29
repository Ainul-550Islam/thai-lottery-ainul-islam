<?php

namespace Tests\Feature\Payment;

use App\Enums\PaymentMethod;
use App\Exceptions\FinancialException;
use App\Services\Payment\Drivers\BankTransferGateway;
use App\Services\Payment\Drivers\StripeGateway;
use App\Services\Payment\PaymentGatewayManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GATEWAY CAPABILITY GUARDS (FINAL AUDIT #5).
 *
 * A gateway driver existing in the container must never be enough for it to
 * be invocable: deposit and withdrawal paths resolve drivers through the
 * fail-closed guard, which refuses anything disabled, unconfigured or not
 * capable of the operation being attempted — before a single record exists.
 */
class PaymentGatewayManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_a_bound_driver_and_refuses_unknown_names(): void
    {
        $manager = $this->app->make(PaymentGatewayManager::class);

        $this->assertInstanceOf(StripeGateway::class, $manager->driver('stripe'));
        $this->assertInstanceOf(StripeGateway::class, $manager->driver(PaymentMethod::Stripe));
        $this->assertTrue($manager->hasDriver('bank_transfer'));
        $this->assertTrue($manager->hasDriver('manual'));

        $this->assertFalse($manager->hasDriver('promptpay'));
        $this->assertFalse($manager->hasDriver('skrill'));

        $this->expectException(FinancialException::class);
        $manager->driver('skrill');
    }

    public function test_is_enabled_reflects_the_enabled_flag_and_defaults_closed(): void
    {
        config(['payment.gateways.stripe.enabled' => false]);

        $manager = $this->app->make(PaymentGatewayManager::class);

        $this->assertFalse($manager->isEnabled('stripe'));
        $this->assertFalse($manager->isEnabled(PaymentMethod::Stripe));
        $this->assertFalse($manager->isDepositCapable('stripe'));
        $this->assertFalse($manager->isWithdrawalCapable('stripe'));

        config(['payment.gateways.stripe.enabled' => true]);

        $this->assertTrue($manager->isEnabled('stripe'));
        $this->assertTrue($manager->isDepositCapable('stripe'));

        // Withdrawal capability is opt-in per driver (supports_withdrawal).
        $this->assertFalse($manager->isWithdrawalCapable('stripe'));
    }

    public function test_deposit_driver_refuses_a_disabled_gateway_with_a_stable_code(): void
    {
        config(['payment.gateways.stripe.enabled' => false]);

        $manager = $this->app->make(PaymentGatewayManager::class);

        try {
            $manager->depositDriver(PaymentMethod::Stripe);
            $this->fail('A disabled gateway must not resolve for deposits.');
        } catch (FinancialException $exception) {
            $this->assertSame('payment_gateway_disabled', $exception->errorCode());
        }
    }

    public function test_deposit_driver_refuses_a_non_deposit_capable_gateway(): void
    {
        config([
            'payment.gateways.stripe.enabled' => true,
            'payment.gateways.stripe.supports_deposit' => false,
        ]);

        $manager = $this->app->make(PaymentGatewayManager::class);

        try {
            $manager->depositDriver(PaymentMethod::Stripe);
            $this->fail('A non-deposit-capable gateway must not resolve for deposits.');
        } catch (FinancialException $exception) {
            $this->assertSame('payment_gateway_not_deposit_capable', $exception->errorCode());
        }
    }

    public function test_withdrawal_driver_refuses_a_non_withdrawal_capable_gateway(): void
    {
        config([
            'payment.gateways.stripe.enabled' => true,
            'payment.gateways.stripe.supports_withdrawal' => false,
        ]);

        $manager = $this->app->make(PaymentGatewayManager::class);

        try {
            $manager->withdrawalDriver(PaymentMethod::Stripe);
            $this->fail('A non-withdrawal-capable gateway must not resolve for payouts.');
        } catch (FinancialException $exception) {
            $this->assertSame('payment_gateway_not_withdrawal_capable', $exception->errorCode());
        }
    }

    public function test_deposit_driver_resolves_an_enabled_deposit_capable_gateway(): void
    {
        config([
            'payment.gateways.stripe.enabled' => true,
            'payment.gateways.stripe.supports_deposit' => true,
        ]);

        $driver = $this->app->make(PaymentGatewayManager::class)->depositDriver(PaymentMethod::Stripe);

        $this->assertInstanceOf(StripeGateway::class, $driver);
    }

    public function test_bank_transfer_resolves_through_the_guard_when_enabled(): void
    {
        // Enabled AND fully configured settlement (FINAL AUDIT #4): without
        // the operator's real settlement account the deposit capability
        // stays off even with the flag on.
        config([
            'payment.gateways.bank_transfer.enabled' => true,
            'payment.gateways.bank_transfer.settlement' => [
                'bank_name' => 'Real Operator Bank',
                'account_number' => '555-666-777',
                'account_name' => 'Real Operator Company Ltd',
                'instructions' => null,
            ],
        ]);

        $manager = $this->app->make(PaymentGatewayManager::class);

        $this->assertInstanceOf(BankTransferGateway::class, $manager->depositDriver(PaymentMethod::BankTransfer));
        $this->assertTrue($manager->isDepositCapable('bank_transfer'));
        $this->assertTrue($manager->isWithdrawalCapable('bank_transfer'));
    }

    public function test_bank_transfer_deposit_capability_stays_off_without_settlement_details(): void
    {
        config([
            'payment.gateways.bank_transfer.enabled' => true,
            'payment.gateways.bank_transfer.settlement' => [
                'bank_name' => null,
                'account_number' => null,
                'account_name' => null,
            ],
        ]);

        $manager = $this->app->make(PaymentGatewayManager::class);

        $this->assertFalse($manager->isDepositCapable('bank_transfer'));

        try {
            $manager->depositDriver(PaymentMethod::BankTransfer);
            $this->fail('An unconfigured bank-transfer lane must not resolve for deposits.');
        } catch (FinancialException $exception) {
            $this->assertSame('payment_gateway_not_deposit_capable', $exception->errorCode());
        }

        // Payouts do not depend on the settlement account: the player
        // supplies their own destination for withdrawals.
        $this->assertTrue($manager->isWithdrawalCapable('bank_transfer'));
    }
}
