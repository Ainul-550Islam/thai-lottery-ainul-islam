<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Exceptions\FinancialException;
use App\Models\Deposit;
use App\Services\Payment\Drivers\BkashGateway;
use App\Services\Payment\Drivers\NagadGateway;
use App\Services\Payment\PaymentGatewayManager;
use Illuminate\Http\Client\Request as OutboundRequest;
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

        self::assertTrue($gateways->isDepositCapable('bkash'));
        self::assertTrue($gateways->isDepositCapable('nagad'));
        self::assertTrue($gateways->isWithdrawalCapable('bkash'));
        self::assertTrue($gateways->isWithdrawalCapable('nagad'));

        config(['payment.currency.default' => 'THB', 'payment.country' => 'TH']);
        self::assertFalse($gateways->isDepositCapable('bkash'));
        self::assertFalse($gateways->isDepositCapable('nagad'));
        self::assertFalse($gateways->isWithdrawalCapable('bkash'));
        self::assertFalse($gateways->isWithdrawalCapable('nagad'));
    }

    public function test_bkash_success_notification_is_executed_and_queried_before_it_becomes_a_success_payload(): void
    {
        config([
            'payment.gateways.bkash' => [
                'enabled' => true, 'app_key' => 'test-app-key', 'app_secret' => 'test-app-secret',
                'username' => 'test-user', 'password' => 'test-password',
                'base_url' => 'https://bkash.example.test', 'webhook_secret' => 'test-webhook-secret',
            ],
        ]);
        $statusQueries = 0;
        Http::fake(static function (OutboundRequest $request) use (&$statusQueries) {
            if (str_ends_with($request->url(), '/tokenized/checkout/token/grant')) {
                return Http::response(['id_token' => 'test-access-token'], 200);
            }
            if (str_ends_with($request->url(), '/tokenized/checkout/payment/status')) {
                $statusQueries++;
                if ($statusQueries === 1) {
                    return Http::response(['transactionStatus' => 'Initiated', 'statusCode' => '0000'], 200);
                }

                return Http::response([
                    'transactionStatus' => 'Completed', 'statusCode' => '0000',
                    'merchantInvoiceNumber' => 'DP-BKASH-VERIFY-01', 'amount' => '250.00',
                    'currency' => 'BDT', 'trxID' => 'BKASH-TRX-001',
                ], 200);
            }
            if (str_contains($request->url(), '/tokenized/checkout/execute/')) {
                return Http::response(['transactionStatus' => 'Completed', 'statusCode' => '0000'], 200);
            }

            return Http::response([], 404);
        });
        $callback = json_encode([
            'paymentID' => 'BKASH-PAYMENT-001', 'merchantInvoiceNumber' => 'DP-BKASH-VERIFY-01',
            'transactionStatus' => 'SUCCESS', 'amount' => '250.00', 'currency' => 'BDT',
        ], JSON_THROW_ON_ERROR);
        $request = Request::create('/webhooks/bkash', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $callback);

        $payload = app(BkashGateway::class)->parseWebhook($request);

        self::assertSame('deposit.success', $payload->eventType->value);
        self::assertSame('250.00', $payload->amount);
        self::assertSame(Currency::BDT, $payload->currency);
        self::assertSame('BKASH-TRX-001', $payload->providerReference);
        self::assertSame('DP-BKASH-VERIFY-01', $payload->internalReference);
        self::assertSame(2, $statusQueries);
        Http::assertSentCount(4);
    }
}
