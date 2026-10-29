<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Exceptions\FinancialException;
use App\Models\Deposit;
use App\Services\Operations\ProviderReadinessService;
use App\Services\Payment\Drivers\BkashGateway;
use App\Services\Payment\Drivers\NagadGateway;
use App\Services\Payment\PaymentGatewayManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

final class BdtGatewayCurrencyGuardTest extends Batch01TestCase
{
    public function test_bkash_and_nagad_refuse_a_thb_deposit_before_any_provider_call(): void
    {
        $deposit = new Deposit;
        $deposit->currency = Currency::THB;
        foreach ([BkashGateway::class, NagadGateway::class] as $gatewayClass) {
            try {
                app($gatewayClass)->initiateDeposit($deposit);
                self::fail($gatewayClass.' must reject THB before making an HTTP request.');
            } catch (FinancialException $exception) {
                self::assertSame('payment_gateway_currency_unsupported', $exception->errorCode());
            }
        }
    }

    public function test_configured_bdt_gateways_are_capable_only_in_the_bangladesh_market(): void
    {
        config([
            'payment.currency.default' => 'BDT',
            'payment.country' => 'BD',
            'payment.markets.bkash' => ['currencies' => ['BDT'], 'countries' => ['BD']],
            'payment.markets.nagad' => ['currencies' => ['BDT'], 'countries' => ['BD']],
            'payment.gateways.bkash' => [
                'enabled' => true, 'supports_deposit' => true, 'supports_withdrawal' => true,
                'app_key' => 'test-app-key', 'app_secret' => 'test-app-secret', 'username' => 'test-user',
                'password' => 'test-password', 'base_url' => 'https://bkash.example.test',
                'webhook_secret' => 'test-webhook-secret', 'supported_currencies' => ['BDT'],
            ],
            'payment.gateways.nagad' => [
                'enabled' => true, 'supports_deposit' => true, 'supports_withdrawal' => true,
                'merchant_id' => 'test-merchant', 'merchant_number' => '01700000000', 'app_key' => 'test-app-key',
                'app_secret' => 'test-app-secret', 'public_key' => 'test-public-key', 'private_key' => 'test-private-key',
                'base_url' => 'https://nagad.example.test', 'webhook_secret' => 'test-webhook-secret',
                'supported_currencies' => ['BDT'],
            ],
        ]);
        $gateways = app(PaymentGatewayManager::class);

        // Neither a local HMAC nor filled-in keys authenticate the providers'
        // actual contracts. Both remain closed for now, including in-market.
        self::assertFalse($gateways->isDepositCapable('bkash'));
        self::assertFalse($gateways->isDepositCapable('nagad'));
        self::assertFalse($gateways->isWithdrawalCapable('bkash'));
        self::assertFalse($gateways->isWithdrawalCapable('nagad'));
        foreach (['bkash', 'nagad'] as $gateway) {
            $issues = app(ProviderReadinessService::class)->gateway($gateway)->issues;
            self::assertContains($gateway.'_callback_authentication_unverified', $issues);
            self::assertNotContains('bdt_market_refused', $issues);
        }

        // Even an accidentally broadened allowlist must not convert a BDT-only
        // provider into a THB or non-Bangladesh merchant-market rail.
        config([
            'payment.markets.bkash' => ['currencies' => ['BDT', 'THB'], 'countries' => ['BD', 'TH']],
            'payment.markets.nagad' => ['currencies' => ['BDT', 'THB'], 'countries' => ['BD', 'TH']],
        ]);
        foreach ([['THB', 'TH'], ['THB', 'BD'], ['BDT', 'TH']] as [$currency, $country]) {
            config(['payment.currency.default' => $currency, 'payment.country' => $country]);
            self::assertFalse($gateways->isDepositCapable('bkash'));
            self::assertFalse($gateways->isDepositCapable('nagad'));
            self::assertFalse($gateways->isWithdrawalCapable('bkash'));
            self::assertFalse($gateways->isWithdrawalCapable('nagad'));
            // A closed authentication lane alone must not mask a broken market
            // guard; assert the independent refusal reason as well.
            foreach (['bkash', 'nagad'] as $gateway) {
                self::assertContains('bdt_market_refused', app(ProviderReadinessService::class)->gateway($gateway)->issues);
            }
        }
    }

    public function test_raw_success_notification_without_authenticated_boundary_never_calls_provider(): void
    {
        config([
            'payment.gateways.bkash.app_key' => 'test-app-key',
            'payment.gateways.bkash.app_secret' => 'test-app-secret',
            'payment.gateways.bkash.username' => 'test-user',
            'payment.gateways.bkash.password' => 'test-password',
            'payment.gateways.bkash.base_url' => 'https://bkash.example.test',
        ]);
        Http::fake();
        $body = json_encode([
            'paymentID' => 'BKASH-PAYMENT-001', 'merchantInvoiceNumber' => 'DP-BKASH-VERIFY-01',
            'transactionStatus' => 'SUCCESS', 'amount' => '250.00', 'currency' => 'BDT',
        ], JSON_THROW_ON_ERROR);
        $request = Request::create('/webhooks/bkash', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
        try {
            app(BkashGateway::class)->parseWebhook($request);
            self::fail('A callback cannot authorize a provider call without an authenticated boundary.');
        } catch (FinancialException $exception) {
            self::assertSame('bkash_payment_verification_failed', $exception->errorCode());
        }
        Http::assertNothingSent();
    }
}
