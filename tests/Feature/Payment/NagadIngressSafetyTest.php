<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\Payment\PaymentWebhookData;
use App\DTOs\Payment\WebhookPayload;
use App\Enums\Currency;
use App\Enums\DepositStatus;
use App\Enums\GatewayIntegrationStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\WebhookEventType;
use App\Exceptions\PaymentWebhookException;
use App\Jobs\Payment\ProcessPaymentWebhookJob;
use App\Models\Deposit;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\WebhookReplayGuard;
use App\Services\Operations\ProviderReadinessService;
use App\Services\Payment\PaymentWebhookVerificationService;
use App\Services\Payment\WebhookReplayGuardService;
use Illuminate\Http\Request;

/** A locally invented Nagad HMAC is not evidence of a verified merchant protocol. */
final class NagadIngressSafetyTest extends PaymentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'payment.currency.default' => 'BDT', 'payment.country' => 'BD',
            'payment.markets.nagad' => ['currencies' => ['BDT'], 'countries' => ['BD']],
            'payment.gateways.nagad.enabled' => true,
            'payment.gateways.nagad.supports_deposit' => true,
            'payment.gateways.nagad.supports_withdrawal' => true,
            'payment.gateways.nagad.supported_currencies' => ['BDT'],
            'payment.gateways.nagad.merchant_number' => '01711111111',
            'payment.gateways.nagad.app_key' => 'synthetic-app-key',
            'payment.gateways.nagad.public_key' => 'synthetic-public-key',
            'payment.gateways.nagad.private_key' => 'synthetic-private-key',
            'payment.gateways.nagad.base_url' => 'https://nagad.example.test',
        ]);
    }

    public function test_configured_keys_do_not_prove_provider_readiness(): void
    {
        $readiness = app(ProviderReadinessService::class)->gateway('nagad', 'deposit');
        self::assertFalse($readiness->ready);
        self::assertSame(GatewayIntegrationStatus::PartiallyImplemented, $readiness->status);
        self::assertContains('nagad_callback_authentication_unverified', $readiness->issues);
        $lane = app(ProviderReadinessService::class)->lane('payment.nagad');
        self::assertSame(ProviderReadinessService::IMPLEMENTATION_INCOMPLETE, $lane['state']);
        self::assertSame([], $lane['missing']);
        self::assertFalse($this->gatewayManager()->isDepositCapable('nagad'));
        self::assertFalse($this->gatewayManager()->isWithdrawalCapable('nagad'));
    }

    public function test_local_hmac_callback_cannot_credit_until_provider_contract_is_verified(): void
    {
        $player = $this->createPlayer('0.00', Currency::BDT);
        $deposit = $this->createPendingDeposit($player['wallet'], '100.00', PaymentMethod::Nagad);
        $body = json_encode(['order_id' => $deposit->reference_number, 'payment_ref_id' => 'nagad-local-hmac',
            'status' => 'SUCCESS', 'amount' => '100.00', 'currency' => 'BDT'], JSON_THROW_ON_ERROR);
        $request = Request::create('/webhook/nagad', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
        $request->headers->set('X-Nagad-Signature', hash_hmac('sha256', $body, self::NAGAD_WEBHOOK_SECRET));

        $result = $this->webhookService()->handleWebhookRequest('nagad', $request);

        self::assertFalse($result->success, 'A local HMAC is not provider authentication; wallet is '.(string) $player['wallet']->fresh()->balance.'.');
        self::assertSame('nagad_callback_authentication_unverified', $result->metadata['error_code']);
        self::assertSame(DepositStatus::Pending, $deposit->fresh()->status);
        self::assertSame('0.00', (string) $player['wallet']->fresh()->balance);
        self::assertSame(0, FinancialTransaction::query()->count());
        self::assertSame(0, LedgerEntry::query()->count());
    }

    public function test_historical_accepted_observation_cannot_reopen_http_ingress(): void
    {
        $player = $this->createPlayer('0.00', Currency::BDT);
        $deposit = $this->createPendingDeposit($player['wallet'], '100.00', PaymentMethod::Nagad);
        $body = json_encode(['order_id' => $deposit->reference_number, 'payment_ref_id' => 'nagad-old-delivery',
            'status' => 'SUCCESS', 'amount' => '100.00', 'currency' => 'BDT'], JSON_THROW_ON_ERROR);
        $server = ['CONTENT_TYPE' => 'application/json',
            'HTTP_X_NAGAD_SIGNATURE' => hash_hmac('sha256', $body, self::NAGAD_WEBHOOK_SECRET)];
        $historical = Request::create('/api/v1/payments/webhook/nagad', 'POST', server: $server, content: $body);
        app(WebhookReplayGuardService::class)->rememberAccepted($historical, 'nagad');
        self::assertSame(1, WebhookReplayGuard::query()->where('gateway', 'nagad')->count());

        for ($i = 0; $i < 2; $i++) {
            $this->call('POST', '/api/v1/payments/webhook/nagad', [], [], [], $server, $body)
                ->assertStatus(422)->assertJsonPath('success', false);
        }
        self::assertSame(1, WebhookReplayGuard::query()->where('gateway', 'nagad')->sole()->deliveries);
        self::assertSame(DepositStatus::Pending, $deposit->fresh()->status);
        self::assertSame('0.00', (string) $player['wallet']->fresh()->balance);
        self::assertSame(0, FinancialTransaction::query()->count());
        self::assertSame(0, LedgerEntry::query()->count());
    }

    public function test_previously_queued_nagad_dto_cannot_bypass_closed_ingress(): void
    {
        $player = $this->createPlayer('0.00', Currency::BDT);
        $deposit = $this->createPendingDeposit($player['wallet'], '100.00', PaymentMethod::Nagad);
        $payload = new WebhookPayload('nagad', 'nagad-queued-evidence', WebhookEventType::DepositSuccess,
            'nagad-queued-transaction', $deposit->reference_number, '100.00', Currency::BDT, true);
        $result = (new ProcessPaymentWebhookJob($payload))->handle($this->webhookService());

        self::assertFalse($result->success, 'A queued DTO is not provider authentication; wallet is '.(string) $player['wallet']->fresh()->balance.'.');
        self::assertSame('nagad_callback_authentication_unverified', $result->metadata['error_code']);
        self::assertSame(DepositStatus::Pending, $deposit->fresh()->status);
        self::assertSame('0.00', (string) $player['wallet']->fresh()->balance);
        self::assertSame(0, FinancialTransaction::query()->count());
        self::assertSame(0, LedgerEntry::query()->count());
    }

    public function test_generic_hmac_envelope_cannot_bypass_nagad_provider_verification(): void
    {
        $player = $this->createPlayer('0.00', Currency::BDT);
        $deposit = $this->createPendingDeposit($player['wallet'], '100.00', PaymentMethod::Nagad);
        $payment = Payment::factory()->create([
            'user_id' => $player['user']->id, 'payable_type' => Deposit::class, 'payable_id' => $deposit->id,
            'method' => PaymentMethod::Nagad, 'gateway' => 'nagad', 'gateway_reference' => 'nagad-envelope-reference',
            'currency' => Currency::BDT, 'amount' => '100.00', 'fee' => '0.00', 'status' => PaymentStatus::Pending,
        ]);
        $payload = ['id' => 'nagad-envelope-test', 'provider' => 'nagad', 'status' => 'succeeded',
            'reference' => 'nagad-envelope-reference', 'amount' => '100.00', 'currency' => 'BDT'];
        $signature = app(PaymentWebhookVerificationService::class)->expectedSignatureFor('nagad', $payload);
        $envelope = PaymentWebhookData::fromInput('nagad', 'nagad-envelope-test', 'payment.success', $signature, now()->toIso8601String(), $payload);

        try {
            $this->webhookService()->processEnvelope($envelope);
            self::fail('A generic local HMAC is not provider authentication; wallet is '.(string) $player['wallet']->fresh()->balance.'.');
        } catch (PaymentWebhookException $exception) {
            self::assertSame(PaymentWebhookException::CODE_MALFORMED, $exception->errorCode());
        }
        self::assertSame(DepositStatus::Pending, $deposit->fresh()->status);
        self::assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        self::assertSame('0.00', (string) $player['wallet']->fresh()->balance);
        self::assertSame(0, FinancialTransaction::query()->count());
        self::assertSame(0, LedgerEntry::query()->count());
    }
}
