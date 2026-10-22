<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use App\Enums\Currency;
use App\Enums\GatewayIntegrationStatus;
use App\Enums\WebhookEventType;
use App\Models\Deposit;
use App\Services\Payment\Drivers\CryptoGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class CryptoGatewayTest extends TestCase
{
    private CryptoGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('payment.gateways.crypto.enabled', true);
        Config::set('payment.gateways.crypto.webhook_secret', 'test_crypto_secret');

        $this->gateway = app(CryptoGateway::class);
    }

    public function test_crypto_is_fail_closed_without_a_provider_contract(): void
    {
        $this->assertSame('crypto', $this->gateway->name());
        $this->assertSame('Cryptocurrency', $this->gateway->label());
        $this->assertSame(GatewayIntegrationStatus::Unsupported, $this->gateway->status());
        $this->assertFalse($this->gateway->supportsDeposit());
        $this->assertFalse($this->gateway->supportsWithdrawal());
        $this->assertFalse($this->gateway->supportsCurrency(Currency::USD));
        $this->assertFalse($this->gateway->supportsCurrency(Currency::THB));
    }

    public function test_crypto_initiation_does_not_create_a_fake_checkout_or_payout(): void
    {
        $deposit = new Deposit;
        $deposit->id = 3;
        $deposit->user_id = 15;
        $deposit->wallet_id = 30;
        $deposit->reference_number = 'DP-20260903-CRYPTO01';
        $deposit->currency = Currency::USD;
        $deposit->amount = '100.00';
        $deposit->fee = '0.00';
        $deposit->net_amount = '100.00';

        $depositResponse = $this->gateway->initiateDeposit($deposit);

        $this->assertFalse($depositResponse->successful);
        $this->assertNull($depositResponse->redirectUrl);
        $this->assertSame('unsupported', $depositResponse->metadata['state']);

        $withdrawal = new \App\Models\Withdrawal;
        $withdrawal->id = 4;
        $withdrawal->reference_number = 'WD-20260903-CRYPTO01';

        $withdrawalResponse = $this->gateway->initiateWithdrawal($withdrawal);

        $this->assertFalse($withdrawalResponse->successful);
        $this->assertNull($withdrawalResponse->providerReference);
    }

    public function test_crypto_webhooks_fail_closed_without_provider_contract(): void
    {
        $payload = [
            'txid' => '0xabcdef1234567890',
            'invoice_id' => 'DP-CRYPTO-REF-01',
            'amount' => '100.00',
            'status' => 'CONFIRMED',
            'confirmations' => 12,
        ];

        $json = json_encode($payload);
        $sig = hash_hmac('sha256', $json, 'test_crypto_secret');

        $request = Request::create(
            uri: '/api/v1/payments/webhook/crypto',
            method: 'POST',
            server: ['HTTP_X_CRYPTO_SIGNATURE' => $sig],
            content: $json,
        );

        $this->assertFalse($this->gateway->verifyWebhookSignature($request));

        $parsed = $this->gateway->parseWebhook($request);
        $this->assertSame('crypto', $parsed->gateway);
        $this->assertSame(WebhookEventType::DepositSuccess, $parsed->eventType);
        $this->assertSame('DP-CRYPTO-REF-01', $parsed->internalReference);
        $this->assertSame('100.00', $parsed->amount);
        $this->assertSame(Currency::USD, $parsed->currency);
        $this->assertTrue($parsed->isSuccess);
    }
}
