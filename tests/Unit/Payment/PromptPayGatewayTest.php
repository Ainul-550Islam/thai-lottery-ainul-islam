<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use App\Enums\Currency;
use App\Enums\GatewayIntegrationStatus;
use App\Enums\PaymentMethod;
use App\Models\Deposit;
use App\Models\Withdrawal;
use App\Services\Payment\Drivers\PromptPayGateway;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\PromptPayQrGeneratorService;
use Illuminate\Http\Request;
use Tests\TestCase;

final class PromptPayGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'payment.gateways.promptpay.enabled' => true,
            'payment.gateways.promptpay.merchant_id' => '0812345678',
            'payment.gateways.promptpay.qr_expiry_minutes' => 15,
        ]);
    }

    public function test_manager_resolves_a_real_driver_and_emv_qr_uses_only_configured_merchant(): void
    {
        $manager = app(PaymentGatewayManager::class);
        $this->assertTrue($manager->hasDriver('promptpay'));
        $this->assertSame('X-KBANK-Signature', config('payment.gateways.promptpay.signature_header'));
        $this->assertSame(['kbank', 'scb'], array_keys(config('payment.gateways.promptpay.bank_webhooks')));
        $driver = $manager->driver(PaymentMethod::PromptPay);
        $this->assertInstanceOf(PromptPayGateway::class, $driver);
        $this->assertSame(GatewayIntegrationStatus::FullyImplemented, $driver->status());

        $response = $driver->initiateDeposit($this->deposit(), [
            'merchant_id' => '9999999999',
            'recipient_id' => '9999999999',
        ]);

        $this->assertTrue($response->successful);
        $this->assertSame('DEP-20261025-ABCDEFGHIJKL', $response->providerReference);
        $payload = $response->metadata['qr_payload'];
        $tags = $this->tags($payload);
        $this->assertSame('01', $tags['00']);
        $this->assertSame('12', $tags['01']);
        $this->assertSame('764', $tags['53']);
        $this->assertSame('100.00', $tags['54']);
        $this->assertSame('TH', $tags['58']);
        $this->assertSame('0525DEP-20261025-ABCDEFGHIJKL', $tags['62']);
        $this->assertStringContainsString('0066812345678', $tags['29']);
        $this->assertStringNotContainsString('9999999999', $payload);
        $this->assertSame($tags['63'], app(PromptPayQrGeneratorService::class)->calculateCrc16Ccitt(substr($payload, 0, -4)));
        $this->assertNotEmpty($response->metadata['expires_at']);
    }

    public function test_currency_and_withdrawal_guards_fail_closed(): void
    {
        $driver = app(PaymentGatewayManager::class)->driver(PaymentMethod::PromptPay);
        $this->assertTrue($driver->supportsDeposit());
        $this->assertFalse($driver->supportsWithdrawal());
        $this->assertTrue($driver->supportsWebhook());
        $this->assertTrue($driver->supportsCurrency(Currency::THB));
        $this->assertFalse($driver->supportsCurrency(Currency::USD));
        $this->assertFalse($driver->supportsCurrency(Currency::BDT));

        config(['payment.gateways.promptpay.supports_withdrawal' => true]);
        $this->assertFalse($driver->supportsWithdrawal());
        $this->assertFalse(app(PaymentGatewayManager::class)->isWithdrawalCapable(PaymentMethod::PromptPay));

        $usd = $driver->initiateDeposit($this->deposit(Currency::USD));
        $this->assertFalse($usd->successful);
        $payout = $driver->initiateWithdrawal(new Withdrawal);
        $this->assertFalse($payout->successful);
        $this->assertFalse($payout->isPending);
        $this->assertSame('PromptPay payouts are not supported', $payout->errorMessage);
    }

    public function test_missing_or_invalid_merchant_disables_the_rail(): void
    {
        config(['payment.gateways.promptpay.merchant_id' => null]);
        $manager = app(PaymentGatewayManager::class);
        $driver = $manager->driver(PaymentMethod::PromptPay);

        $this->assertSame(GatewayIntegrationStatus::ConfiguredOnly, $driver->status());
        $this->assertFalse($driver->isEnabled());
        $this->assertFalse($manager->isEnabled(PaymentMethod::PromptPay));
        $this->assertFalse($driver->initiateDeposit($this->deposit())->successful);

        config(['payment.gateways.promptpay.merchant_id' => 'not-a-merchant']);
        $this->assertFalse($driver->isEnabled());
    }

    public function test_webhook_signature_verification_delegates_to_the_configured_bank_lanes(): void
    {
        config([
            'services.kbank.webhook_secret' => 'kbank-driver-verification-secret',
            'services.scb.webhook_secret' => 'scb-driver-verification-secret',
        ]);
        $driver = app(PaymentGatewayManager::class)->driver(PaymentMethod::PromptPay);

        foreach (['KBANK' => 'X-KBANK-Signature', 'SCB' => 'X-SCB-Signature'] as $bank => $header) {
            $payload = ['event_type' => 'credit', 'timestamp' => (string) time()];
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $secret = $bank === 'KBANK' ? 'kbank-driver-verification-secret' : 'scb-driver-verification-secret';
            $request = Request::create('/api/v1/payments/bank-webhook/'.strtolower($bank), 'POST', [], [], [], [], $json);
            $request->headers->set('CONTENT_TYPE', 'application/json');
            $request->headers->set($header, hash_hmac('sha256', $json, $secret));

            $this->assertTrue($driver->verifyWebhookSignature($request));
        }
    }

    private function deposit(Currency $currency = Currency::THB): Deposit
    {
        $deposit = new Deposit;
        $deposit->forceFill([
            'reference_number' => 'DEP-20261025-ABCDEFGHIJKL',
            'method' => PaymentMethod::PromptPay,
            'currency' => $currency,
            'amount' => '100.00',
            'fee' => '0.00',
            'net_amount' => '100.00',
        ]);

        return $deposit;
    }

    /** @return array<string, string> */
    private function tags(string $payload): array
    {
        $tags = [];
        $offset = 0;
        while ($offset < strlen($payload)) {
            $tag = substr($payload, $offset, 2);
            $length = (int) substr($payload, $offset + 2, 2);
            $tags[$tag] = substr($payload, $offset + 4, $length);
            $offset += 4 + $length;
        }

        return $tags;
    }
}
