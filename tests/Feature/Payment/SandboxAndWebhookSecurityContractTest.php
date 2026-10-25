<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Enums\GatewayIntegrationStatus;
use App\Enums\WalletStatus;
use App\Enums\WalletType;
use App\Enums\WebhookEventType;
use App\Exceptions\FinancialException;
use App\Models\Deposit;
use App\Models\DepositTransaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Payment\Drivers\SandboxDeterministicGateway;
use App\Services\Payment\KasikornBankWebhookService;
use App\Services\Payment\PaymentDecimalNormalizer;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\ScbOpenApiWebhookService;
use App\Services\Payment\TrueMoneyWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class SandboxAndWebhookSecurityContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_sandbox_deterministic_gateway_enforces_non_prod_non_live_boundary_and_hmac_contract(): void
    {
        config([
            'real_money.enabled' => false,
            'payment.gateways.sandbox.enabled' => true,
            'payment.gateways.sandbox.webhook_secret' => 'sbx-hmac-secret-2026',
            'payment.webhooks.timestamp_tolerance' => 300,
        ]);

        /** @var PaymentGatewayManager $manager */
        $manager = app(PaymentGatewayManager::class);
        $gateway = $manager->driver('sandbox');

        $this->assertInstanceOf(SandboxDeterministicGateway::class, $gateway);
        $this->assertSame(GatewayIntegrationStatus::Simulated, $gateway->status());
        // Simulated adapters must NEVER appear as live enabled player gateways.
        $this->assertFalse($manager->isEnabled('sandbox'));
        $this->assertTrue($gateway->isEnabled());

        $user = User::factory()->create();
        $wallet = Wallet::factory()->for($user)->create([
            'currency' => Currency::THB,
            'type' => WalletType::Primary,
            'balance' => '0.00',
            'locked_balance' => '0.00',
            'status' => WalletStatus::Active,
        ]);

        $deposit = new Deposit;
        $deposit->forceFill([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'reference' => 'DEP-SBX-1001',
            'amount' => '250.00',
            'currency' => Currency::THB,
        ]);

        $init = $gateway->initiateDeposit($deposit);
        $this->assertTrue($init->successful);
        $this->assertStringStartsWith('SBX-DEP-', (string) $init->providerReference);
        $this->assertSame('250.00', $init->metadata['bound_amount'] ?? null);
        $this->assertSame('THB', $init->metadata['bound_currency'] ?? null);

        // Signed webhook verification + secret sanitization.
        $rawPayload = json_encode([
            'event_id' => 'evt_sbx_001',
            'event_type' => WebhookEventType::DepositSuccess->value,
            'provider_reference' => $init->providerReference,
            'internal_reference' => 'DEP-SBX-1001',
            'amount' => '250.00',
            'currency' => 'THB',
            'access_token' => 'super-secret-oauth-token',
            'nested' => ['card_number' => '4111222233334444'],
        ], JSON_THROW_ON_ERROR);

        $validSig = SandboxDeterministicGateway::signPayload($rawPayload, 'sbx-hmac-secret-2026');
        $validReq = Request::create('/webhooks/sandbox', 'POST', [], [], [], [
            'HTTP_X_SANDBOX_SIGNATURE' => $validSig,
            'CONTENT_TYPE' => 'application/json',
        ], $rawPayload);

        $this->assertTrue($gateway->verifyWebhookSignature($validReq));
        $parsed = $gateway->parseWebhook($validReq);
        $this->assertTrue($parsed->isSuccess);
        $this->assertSame('250.00', $parsed->amount);
        $this->assertSame(Currency::THB, $parsed->currency);
        $this->assertSame('[REDACTED]', $parsed->rawData['access_token']);
        $this->assertSame('[REDACTED]', $parsed->rawData['nested']['card_number']);

        // Tampered body -> signature verification fails.
        $tamperedReq = Request::create('/webhooks/sandbox', 'POST', [], [], [], [
            'HTTP_X_SANDBOX_SIGNATURE' => $validSig,
        ], str_replace('250.00', '9999.00', $rawPayload));
        $this->assertFalse($gateway->verifyWebhookSignature($tamperedReq));

        // Stale timestamp outside replay window -> signature verification fails.
        $staleSig = SandboxDeterministicGateway::signPayload($rawPayload, 'sbx-hmac-secret-2026', now()->timestamp - 900);
        $staleReq = Request::create('/webhooks/sandbox', 'POST', [], [], [], [
            'HTTP_X_SANDBOX_SIGNATURE' => $staleSig,
        ], $rawPayload);
        $this->assertFalse($gateway->verifyWebhookSignature($staleReq));

        // Enabling real_money MUST immediately hard-block SandboxDeterministicGateway.
        config(['real_money.enabled' => true]);
        $this->assertFalse($gateway->isEnabled());
        $this->expectException(FinancialException::class);
        $gateway->initiateDeposit($deposit);
    }

    public function test_deposit_webhook_controller_enforces_secret_hmac_replay_idempotency_and_terms_conflict(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->for($user)->create([
            'currency' => Currency::THB,
            'type' => WalletType::Primary,
            'balance' => '100.00',
            'locked_balance' => '0.00',
            'status' => WalletStatus::Active,
        ]);

        $body = json_encode([
            'user_id' => (string) $user->id,
            'transaction_id' => 'WH-SANDBOX-TX-9001',
            'amount' => '300.0000',
            'fee' => '0.0000',
            'currency' => 'THB',
            'api_secret' => 'must-never-persist-in-cleartext',
        ], JSON_THROW_ON_ERROR);

        // 1. Unconfigured secret -> 503 WEBHOOK_NOT_CONFIGURED.
        config(['payment.gateways.sandbox.webhook_secret' => null, 'services.sandbox.webhook_secret' => null]);
        $this->call('POST', '/api/v1/webhooks/deposit/sandbox', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(503)->assertJsonPath('error', 'WEBHOOK_NOT_CONFIGURED');

        // 2. Invalid signature -> 401 INVALID_WEBHOOK_SIGNATURE.
        $secret = 'wh-controller-test-secret-99';
        config(['payment.gateways.sandbox.webhook_secret' => $secret]);

        $this->call('POST', '/api/v1/webhooks/deposit/sandbox', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_SIGNATURE' => 'deadbeef',
        ], $body)->assertStatus(401)->assertJsonPath('error', 'INVALID_WEBHOOK_SIGNATURE');

        // 3. Stale X-Webhook-Timestamp -> 401 INVALID_WEBHOOK_SIGNATURE.
        $staleTs = (string) (now()->timestamp - 1200);
        $staleMac = hash_hmac('sha256', $staleTs.'.'.$body, $secret);
        $this->call('POST', '/api/v1/webhooks/deposit/sandbox', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_TIMESTAMP' => $staleTs,
            'HTTP_X_WEBHOOK_SIGNATURE' => $staleMac,
        ], $body)->assertStatus(401)->assertJsonPath('error', 'INVALID_WEBHOOK_SIGNATURE');

        // 4. Float amount in JSON body -> 400 INVALID_WEBHOOK_PAYLOAD (strict string/int enforcement).
        $floatBody = json_encode([
            'user_id' => (string) $user->id,
            'transaction_id' => 'WH-FLOAT-REJECT',
            'amount' => 300.55,
            'currency' => 'THB',
        ], JSON_THROW_ON_ERROR);
        $floatSig = hash_hmac('sha256', $floatBody, $secret);
        $this->call('POST', '/api/v1/webhooks/deposit/sandbox', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_SIGNATURE' => $floatSig,
        ], $floatBody)->assertStatus(400)->assertJsonPath('error', 'INVALID_WEBHOOK_PAYLOAD');

        // 5. Valid timestamped HMAC webhook -> 200 OK, credits wallet once, redacts secret in stored payload.
        $freshTs = (string) now()->timestamp;
        $validSig = hash_hmac('sha256', $freshTs.'.'.$body, $secret);

        $first = $this->call('POST', '/api/v1/webhooks/deposit/sandbox', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_TIMESTAMP' => $freshTs,
            'HTTP_X_WEBHOOK_SIGNATURE' => $validSig,
        ], $body);
        $first->assertOk()->assertJsonPath('status', 'DEPOSIT_PROCESSED');

        $wallet->refresh();
        $this->assertSame('400.00', bcadd((string) $wallet->balance, '0.00', 2));

        $stored = DepositTransaction::query()->where('gateway_reference_id', 'WH-SANDBOX-TX-9001')->firstOrFail();
        $this->assertSame('[REDACTED]', $stored->gateway_raw_payload['api_secret'] ?? null);

        // 6. Duplicate webhook delivery with identical terms -> 200 idempotent replay, NO double credit!
        $duplicate = $this->call('POST', '/api/v1/webhooks/deposit/sandbox', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_TIMESTAMP' => $freshTs,
            'HTTP_X_WEBHOOK_SIGNATURE' => $validSig,
        ], $body);
        $duplicate->assertOk();
        $this->assertSame('400.00', bcadd((string) $wallet->fresh()->balance, '0.00', 2));

        // 7. Replaying same transaction_id with altered amount -> 409 WEBHOOK_TERMS_CONFLICT!
        $conflictingBody = json_encode([
            'user_id' => (string) $user->id,
            'transaction_id' => 'WH-SANDBOX-TX-9001',
            'amount' => '900.0000',
            'fee' => '0.0000',
            'currency' => 'THB',
        ], JSON_THROW_ON_ERROR);
        $conflictSig = hash_hmac('sha256', $freshTs.'.'.$conflictingBody, $secret);

        $this->call('POST', '/api/v1/webhooks/deposit/sandbox', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_TIMESTAMP' => $freshTs,
            'HTTP_X_WEBHOOK_SIGNATURE' => $conflictSig,
        ], $conflictingBody)->assertStatus(409)->assertJsonPath('error', 'WEBHOOK_TERMS_CONFLICT');

        $this->assertSame('400.00', bcadd((string) $wallet->fresh()->balance, '0.00', 2));
    }

    public function test_unconfigured_providers_fail_closed_and_decimal_normalizer_rejects_imprecise_inputs(): void
    {
        // TrueMoney fail-closed when credentials absent.
        config([
            'services.truemoney.enabled' => false,
            'services.truemoney.api_url' => '',
            'services.truemoney.mobile' => '',
            'services.truemoney.api_key' => '',
        ]);
        $tm = app(TrueMoneyWalletService::class);

        try {
            $tm->redeemVoucher('1', 'https://gift.truemoney.com/campaign/?v='.str_repeat('a', 36), '127.0.0.1');
            $this->fail('Expected TrueMoney redeemVoucher to fail closed when unconfigured.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not configured', $e->getMessage());
        }

        // KBank & SCB fail-closed when webhook secrets absent.
        config(['services.kbank.webhook_secret' => '', 'services.scb.webhook_secret' => '']);
        try {
            app(KasikornBankWebhookService::class)->processWebhook(['tx_id' => '1'], 'sig', '127.0.0.1');
            $this->fail('Expected KBank to fail closed without secret.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not configured', $e->getMessage());
        }

        try {
            app(ScbOpenApiWebhookService::class)->processWebhook(['transactionId' => '1'], 'sig', '127.0.0.1');
            $this->fail('Expected SCB to fail closed without secret.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not configured', $e->getMessage());
        }

        // PaymentDecimalNormalizer precision & scientific notation checks.
        $this->assertSame('125.50', PaymentDecimalNormalizer::normalizePositive('125.5', 2));
        $this->assertSame('80.0000', PaymentDecimalNormalizer::normalizePositive(80, 4));

        foreach (['0', '-10.00', '1e5', '12.345', 'NaN', ''] as $bad) {
            $rejected = false;
            try {
                PaymentDecimalNormalizer::normalizePositive($bad, 2);
            } catch (InvalidArgumentException) {
                $rejected = true;
            }
            $this->assertTrue($rejected, "Expected [{$bad}] to be rejected by PaymentDecimalNormalizer.");
        }

        // Inexact IEEE-754 float (0.1 + 0.2 = 0.30000000000000004) rejected by inbound webhook normalizer.
        $this->expectException(InvalidArgumentException::class);
        PaymentDecimalNormalizer::normalizeInboundWebhookPositive(0.1 + 0.2, 4);
    }
}
