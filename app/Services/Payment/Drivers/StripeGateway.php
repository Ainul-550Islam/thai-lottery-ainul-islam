<?php

declare(strict_types=1);

namespace App\Services\Payment\Drivers;

use App\DTOs\Payment\GatewayDepositResponse;
use App\DTOs\Payment\GatewayWithdrawalResponse;
use App\DTOs\Payment\WebhookPayload;
use App\Enums\Currency;
use App\Enums\GatewayIntegrationStatus;
use App\Enums\WebhookEventType;
use App\Models\Deposit;
use App\Models\Withdrawal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Real Stripe Payment Gateway Driver.
 *
 * SPECIFICATION & OFFICIAL PROTOCOL
 * ---------------------------------
 * 1. Signature Verification:
 *    Header: `Stripe-Signature` formatted as `t={timestamp},v1={hash}(,v0={...})?`
 *    Signed Payload: `{$timestamp}.{$requestBody}`
 *    Algorithm: HMAC-SHA256 with webhook_secret
 *    Replay Protection: Timestamp must be within max_age_seconds (default 300s).
 *
 * 2. Checkout / Payment Intent:
 *    Generates Stripe Checkout Session with exact smallest-currency unit conversion
 *    (THB / USD: amount * 100).
 *
 * 3. Webhook Events:
 *    `checkout.session.completed`, `payment_intent.succeeded` -> DepositSuccess
 *    `payment_intent.payment_failed` -> DepositFailed
 *    `charge.refunded` -> ChargeRefunded
 */
class StripeGateway extends AbstractPaymentGateway
{
    public function name(): string
    {
        return 'stripe';
    }

    public function label(): string
    {
        return 'Stripe';
    }

    public function status(): GatewayIntegrationStatus
    {
        return GatewayIntegrationStatus::FullyImplemented;
    }

    public function initiateDeposit(Deposit $deposit, array $options = []): GatewayDepositResponse
    {
        $secretKey = $this->secretKey();

        if (empty($secretKey)) {
            return GatewayDepositResponse::failed(
                'Stripe API secret key is not configured.',
                [],
                ['gateway' => $this->name()],
            );
        }

        $currency = $deposit->currency ?? Currency::THB;
        $amountInCents = bcmul((string) $deposit->amount, '100', 0);

        $params = [
            'mode' => 'payment',
            'client_reference_id' => $deposit->reference_number,
            'success_url' => $this->successUrl().'?session_id={CHECKOUT_SESSION_ID}&reference='.$deposit->reference_number,
            'cancel_url' => $this->cancelUrl().'?reference='.$deposit->reference_number,
            'payment_method_types' => ['card'],
            'line_items' => [
                [
                    'price_data' => [
                        'currency' => strtolower($currency->value),
                        'unit_amount' => (int) $amountInCents,
                        'product_data' => [
                            'name' => 'Wallet Deposit '.$deposit->reference_number,
                            'description' => 'Deposit to Thai Lottery wallet',
                        ],
                    ],
                    'quantity' => 1,
                ],
            ],
            'metadata' => [
                'deposit_reference' => $deposit->reference_number,
                'deposit_id' => (string) $deposit->id,
                'user_id' => (string) $deposit->user_id,
                'checkout_attempt' => $options['checkout_attempt'] ?? '',
                'account_scope' => $options['account_scope'] ?? '',
            ],
        ];

        try {
            $stableReference = is_string($deposit->uuid) && $deposit->uuid !== ''
                ? $deposit->uuid
                : $deposit->reference_number;
            $response = Http::withToken($secretKey)
                ->withHeaders(['Idempotency-Key' => $options['checkout_dispatch_key'] ?? 'deposit-'.$stableReference])
                ->asForm()
                ->post('https://api.stripe.com/v1/checkout/sessions', $params);

            if ($response->successful()) {
                $data = $response->json();
                $sessionId = (string) ($data['id'] ?? '');
                $checkoutUrl = (string) ($data['url'] ?? '');

                return GatewayDepositResponse::redirect(
                    url: $checkoutUrl,
                    providerReference: $sessionId,
                    rawResponse: $data,
                    metadata: [
                        'session_id' => $sessionId,
                        'payment_intent' => $data['payment_intent'] ?? null,
                    ],
                );
            }

            $errorData = $response->json();
            $providerError = is_array($errorData) && is_array($errorData['error'] ?? null)
                ? $errorData['error']
                : [];
            $providerCode = $providerError['code'] ?? null;

            Log::warning('Stripe checkout request was refused', [
                'http_status' => $response->status(),
                'provider_error_code' => is_string($providerCode) ? substr($providerCode, 0, 64) : null,
            ]);

            return GatewayDepositResponse::failed(
                'Stripe checkout creation failed.',
                [],
                ['gateway' => 'stripe', 'http_status' => $response->status()],
            );
        } catch (\Throwable $e) {
            Log::error('Stripe deposit initiation failed', [
                'exception_class' => $e::class,
                'exception_code' => $e->getCode(),
            ]);

            return GatewayDepositResponse::failed('Stripe could not be reached to create checkout.');
        }
    }

    public function initiateWithdrawal(Withdrawal $withdrawal, array $options = []): GatewayWithdrawalResponse
    {
        return GatewayWithdrawalResponse::failed('Automated Stripe payouts require Stripe Connect configuration.');
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        $secret = $this->webhookSecret();

        if (empty($secret)) {
            Log::warning('Stripe webhook rejected: webhook_secret is missing in config.');

            return false;
        }

        $signatureHeader = (string) $request->header('Stripe-Signature', '');

        if ($signatureHeader === '') {
            return false;
        }

        $parsed = $this->parseStripeSignatureHeader($signatureHeader);
        $timestamp = $parsed['t'] ?? null;
        $signatures = $parsed['v1'] ?? [];

        if ($timestamp === null || empty($signatures)) {
            return false;
        }

        // Validate timestamp age (replay protection)
        $maxAge = (int) $this->config->get('payment.webhook.max_age_seconds', 300);
        $currentTime = time();
        $eventTime = (int) $timestamp;

        if (abs($currentTime - $eventTime) > $maxAge) {
            Log::warning('Stripe webhook rejected: timestamp drift exceeds tolerance.', [
                'event_time' => $eventTime,
                'current_time' => $currentTime,
                'tolerance' => $maxAge,
            ]);

            return false;
        }

        $signedPayload = "{$timestamp}.".$request->getContent();
        $expectedSignature = hash_hmac('sha256', $signedPayload, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expectedSignature, $signature)) {
                return true;
            }
        }

        return false;
    }

    public function parseWebhook(Request $request): WebhookPayload
    {
        $data = $request->json()->all();
        $eventId = (string) ($data['id'] ?? 'evt_'.bin2hex(random_bytes(8)));
        $type = (string) ($data['type'] ?? '');
        $object = $data['data']['object'] ?? [];

        $internalReference = $object['client_reference_id']
            ?? $object['metadata']['deposit_reference']
            ?? $object['metadata']['reference_number']
            ?? null;

        $providerReference = $object['id'] ?? null;
        $currencyRaw = $object['currency'] ?? null;
        $currency = is_string($currencyRaw) ? Currency::tryFrom(strtoupper($currencyRaw)) : null;
        $amountRaw = $object['amount_total'] ?? $object['amount'] ?? null;
        // Minor units are exact integers, not floating point or decimal amounts.
        $amount = (is_int($amountRaw) || is_string($amountRaw))
            && preg_match('/\A[0-9]+\z/D', (string) $amountRaw) === 1
            ? bcdiv((string) $amountRaw, '100', 2) : null;

        $eventType = match ($type) {
            'checkout.session.completed', 'payment_intent.succeeded' => WebhookEventType::DepositSuccess,
            'payment_intent.payment_failed', 'checkout.session.expired' => WebhookEventType::DepositFailed,
            'charge.refunded' => WebhookEventType::ChargeRefunded,
            'charge.dispute.created' => WebhookEventType::DisputeCreated,
            default => WebhookEventType::Unknown,
        };

        $isSuccess = $eventType->isSuccess();
        $failureReason = null;

        if ($eventType === WebhookEventType::DepositFailed) {
            $failureReason = $object['last_payment_error']['message'] ?? 'Stripe payment was declined or failed.';
        }

        return new WebhookPayload(
            gateway: $this->name(),
            eventId: $eventId,
            eventType: $eventType,
            providerReference: $providerReference,
            internalReference: $internalReference,
            amount: $amount,
            currency: $currency,
            isSuccess: $isSuccess,
            failureReason: $failureReason,
            rawData: $data,
            metadata: [
                'stripe_event_type' => $type,
                'payment_intent' => $object['payment_intent'] ?? null,
            ],
        );
    }

    private function secretKey(): ?string
    {
        $key = $this->config->get('payment.gateways.stripe.secret');

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }

    /**
     * @return array{t: string|null, v1: list<string>}
     */
    private function parseStripeSignatureHeader(string $header): array
    {
        $timestamp = null;
        $signatures = [];

        $items = explode(',', $header);

        foreach ($items as $item) {
            $parts = explode('=', trim($item), 2);

            if (count($parts) === 2) {
                if ($parts[0] === 't') {
                    $timestamp = $parts[1];
                } elseif ($parts[0] === 'v1') {
                    $signatures[] = $parts[1];
                }
            }
        }

        return [
            't' => $timestamp,
            'v1' => $signatures,
        ];
    }
}
