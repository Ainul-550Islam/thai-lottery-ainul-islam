<?php

declare(strict_types=1);

namespace App\Services\Payment\Drivers;

use App\DTOs\Payment\GatewayDepositResponse;
use App\DTOs\Payment\GatewayWithdrawalResponse;
use App\DTOs\Payment\WebhookPayload;
use App\Enums\Currency;
use App\Enums\GatewayIntegrationStatus;
use App\Enums\WebhookEventType;
use App\Exceptions\FinancialException;
use App\Models\Deposit;
use App\Models\Withdrawal;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\PaymentDecimalNormalizer;
use App\Services\Payment\PaymentLogSanitizer;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;

/**
 * Deterministic non-production payment adapter implementing the full
 * PaymentGatewayInterface contract for integration, webhook HMAC, replay,
 * amount/currency/order binding, and terminal-state verification without
 * live provider credentials.
 *
 * SAFETY BOUNDARIES:
 * - Reports GatewayIntegrationStatus::Simulated so PaymentGatewayManager::isEnabled()
 *   never exposes it as a live player payment option.
 * - Fails closed immediately in APP_ENV=production or when real_money.enabled=true.
 */
final class SandboxDeterministicGateway implements PaymentGatewayInterface
{
    public function __construct(
        private readonly ConfigRepository $config,
    ) {}

    public function name(): string
    {
        return 'sandbox';
    }

    public function label(): string
    {
        return 'Deterministic Sandbox Gateway (Non-Production Only)';
    }

    public function status(): GatewayIntegrationStatus
    {
        return GatewayIntegrationStatus::Simulated;
    }

    public function isEnabled(): bool
    {
        if ($this->isForbiddenEnvironment()) {
            return false;
        }

        return (bool) $this->config->get('payment.gateways.sandbox.enabled', false)
            && trim((string) $this->config->get('payment.gateways.sandbox.webhook_secret', '')) !== '';
    }

    public function supportsDeposit(): bool
    {
        return (bool) $this->config->get('payment.gateways.sandbox.supports_deposit', true);
    }

    public function supportsWithdrawal(): bool
    {
        return (bool) $this->config->get('payment.gateways.sandbox.supports_withdrawal', true);
    }

    public function supportsWebhook(): bool
    {
        return true;
    }

    public function supportsCurrency(Currency $currency): bool
    {
        $supported = (array) $this->config->get('payment.gateways.sandbox.supported_currencies', ['THB']);

        return in_array($currency->value, $supported, true);
    }

    public function initiateDeposit(Deposit $deposit, array $options = []): GatewayDepositResponse
    {
        $this->assertSandboxPermitted();

        $currency = $deposit->currency instanceof Currency
            ? $deposit->currency
            : Currency::from(strtoupper((string) $deposit->currency));

        if (! $this->supportsCurrency($currency)) {
            return GatewayDepositResponse::failed("Currency [{$currency->value}] is not supported by sandbox gateway.");
        }

        $amount = PaymentDecimalNormalizer::normalizePositive((string) $deposit->amount, 2, 'deposit.amount');
        $internalRef = (string) ($deposit->reference ?? ('DEP-'.$deposit->getKey()));
        $providerRef = 'SBX-DEP-'.substr(hash('sha256', $internalRef.'|'.$amount.'|'.$currency->value), 0, 20);

        return GatewayDepositResponse::redirect(
            url: 'https://sandbox.invalid/checkout/'.rawurlencode($providerRef),
            providerReference: $providerRef,
            rawResponse: [
                'sandbox' => true,
                'internal_reference' => $internalRef,
                'amount' => $amount,
                'currency' => $currency->value,
            ],
            metadata: [
                'integration_status' => GatewayIntegrationStatus::Simulated->value,
                'bound_amount' => $amount,
                'bound_currency' => $currency->value,
                'bound_order' => $internalRef,
            ],
        );
    }

    public function initiateWithdrawal(Withdrawal $withdrawal, array $options = []): GatewayWithdrawalResponse
    {
        $this->assertSandboxPermitted();

        $outcomeMode = strtolower(trim((string) ($options['sandbox_outcome'] ?? 'pending')));
        $internalRef = (string) ($withdrawal->reference ?? ('WD-'.$withdrawal->getKey()));
        $providerRef = 'SBX-WD-'.substr(hash('sha256', $internalRef), 0, 20);

        return match ($outcomeMode) {
            'completed' => GatewayWithdrawalResponse::completed($providerRef, ['sandbox' => true]),
            'failed' => GatewayWithdrawalResponse::failed('Simulated deterministic payout rejection.', ['sandbox' => true]),
            'uncertain' => GatewayWithdrawalResponse::uncertain($providerRef, ['sandbox' => true]),
            default => GatewayWithdrawalResponse::pending($providerRef, ['sandbox' => true]),
        };
    }

    /**
     * Verifies `X-Sandbox-Signature: t=<unix>,v1=<hex_hmac_sha256(t + "." + rawBody, secret)>`
     * with strict timestamp tolerance (`payment.webhooks.timestamp_tolerance`, default 300s).
     */
    public function verifyWebhookSignature(Request $request): bool
    {
        if ($this->isForbiddenEnvironment()) {
            return false;
        }

        $secret = trim((string) $this->config->get('payment.gateways.sandbox.webhook_secret', ''));
        if ($secret === '') {
            return false;
        }

        $headerName = (string) $this->config->get('payment.gateways.sandbox.signature_header', 'X-Sandbox-Signature');
        $headerValue = trim((string) $request->header($headerName, ''));
        if ($headerValue === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $headerValue) as $segment) {
            $kv = explode('=', trim($segment), 2);
            if (count($kv) === 2) {
                $parts[trim($kv[0])] = trim($kv[1]);
            }
        }

        $timestampStr = $parts['t'] ?? '';
        $signatureHex = $parts['v1'] ?? '';

        if ($timestampStr === '' || ! ctype_digit($timestampStr) || $signatureHex === '') {
            return false;
        }

        $tolerance = max(1, (int) $this->config->get('payment.webhooks.timestamp_tolerance', 300));
        $timestamp = (int) $timestampStr;
        if (abs(now()->timestamp - $timestamp) > $tolerance) {
            return false;
        }

        $signedPayload = $timestampStr.'.'.$request->getContent();
        $expected = hash_hmac('sha256', $signedPayload, $secret);

        return hash_equals($expected, $signatureHex);
    }

    public function parseWebhook(Request $request): WebhookPayload
    {
        $this->assertSandboxPermitted();

        $decoded = json_decode($request->getContent(), true);
        if (! is_array($decoded)) {
            throw FinancialException::withCode('invalid_webhook_payload', 'Malformed JSON webhook body.');
        }

        $eventId = trim((string) ($decoded['event_id'] ?? ''));
        $typeRaw = trim((string) ($decoded['event_type'] ?? ''));
        $eventType = WebhookEventType::tryFrom($typeRaw);

        if ($eventId === '' || ! $eventType instanceof WebhookEventType) {
            throw FinancialException::withCode('invalid_webhook_payload', 'Missing or unsupported sandbox webhook event_id or event_type.');
        }

        $amount = null;
        if (isset($decoded['amount']) && (is_string($decoded['amount']) || is_int($decoded['amount']))) {
            $amount = PaymentDecimalNormalizer::normalizePositive($decoded['amount'], 2, 'webhook.amount');
        }

        $currency = null;
        if (isset($decoded['currency']) && is_string($decoded['currency']) && trim($decoded['currency']) !== '') {
            $currency = Currency::tryFrom(strtoupper(trim($decoded['currency'])));
            if (! $currency instanceof Currency) {
                throw FinancialException::withCode('invalid_webhook_payload', 'Unsupported webhook currency.');
            }
        }

        $isSuccess = in_array($eventType, [
            WebhookEventType::DepositSuccess,
            WebhookEventType::WithdrawalSuccess,
        ], true);

        return new WebhookPayload(
            gateway: $this->name(),
            eventId: $eventId,
            eventType: $eventType,
            providerReference: isset($decoded['provider_reference']) ? trim((string) $decoded['provider_reference']) : null,
            internalReference: isset($decoded['internal_reference']) ? trim((string) $decoded['internal_reference']) : null,
            amount: $amount,
            currency: $currency,
            isSuccess: $isSuccess,
            failureReason: isset($decoded['failure_reason']) ? trim((string) $decoded['failure_reason']) : null,
            rawData: PaymentLogSanitizer::sanitize($decoded),
            metadata: [
                'sandbox' => true,
            ],
        );
    }

    public static function signPayload(string $rawJson, string $secret, ?int $timestamp = null): string
    {
        $ts = $timestamp ?? now()->timestamp;
        $mac = hash_hmac('sha256', $ts.'.'.$rawJson, $secret);

        return sprintf('t=%d,v1=%s', $ts, $mac);
    }

    private function assertSandboxPermitted(): void
    {
        if ($this->isForbiddenEnvironment()) {
            throw FinancialException::withCode(
                'sandbox_gateway_forbidden',
                'SandboxDeterministicGateway cannot execute in production or when real_money is enabled.',
            );
        }

        if (! $this->isEnabled()) {
            throw FinancialException::withCode(
                'payment_gateway_disabled',
                'SandboxDeterministicGateway is disabled or missing PAYMENT_SANDBOX_WEBHOOK_SECRET.',
            );
        }
    }

    private function isForbiddenEnvironment(): bool
    {
        $env = strtolower((string) $this->config->get('app.env', 'production'));

        return $env === 'production' || (bool) $this->config->get('real_money.enabled', false);
    }
}
