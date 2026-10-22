<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\DTOs\Payment\WebhookProcessingResult;
use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Models\WebhookReceipt;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService;
use Throwable;

/**
 * The narrow "credit a wallet once" inbound-webhook contract.
 *
 * Deliberately separate from the gateway-envelope pipeline
 * (PaymentWebhookService::processEnvelope): this is the entry point a
 * provider delivery lands on when the payload already names the member, the
 * amount and the outcome, and the only question is whether the money has
 * been credited.
 *
 * IDEMPOTENCY IS THE CONTRACT
 * Providers retry deliveries they were never acknowledged for; that is
 * correct behaviour on their side, so a duplicate delivery must be a
 * SUCCESS that moves nothing, not an error that invites another retry. The
 * provider + external id pair is the replay identity: it is carried into
 * the financial transaction's globally unique idempotency key, so the
 * ledger layer itself refuses a second credit even if this service is
 * bypassed, and a WebhookReceipt row makes the delivery (and its replays)
 * visible for triage.
 *
 * Only a `completed` status credits. Pending, failed and cancelled
 * notifications are understood and intentionally ignored — they describe
 * money that has not arrived, and crediting on them would be fabrication.
 *
 * SIGNATURE VERIFICATION IS NOT THIS CLASS'S JOB: authenticity is checked
 * by the webhook signature middleware before a payload reaches here.
 */
final class ProcessPaymentWebhookService
{
    public function __construct(private readonly WalletService $wallets) {}

    /**
     * @param  array<string, mixed>  $payload  provider, external_id, amount, currency, status, user_id
     */
    public function process(string $provider, array $payload, string $externalId): WebhookProcessingResult
    {
        $provider = strtolower(trim($provider));
        $externalId = trim($externalId);

        if ($provider === '' || $externalId === '') {
            return WebhookProcessingResult::failed($provider, $externalId, 'A provider and an external id are required.');
        }

        $status = strtolower((string) ($payload['status'] ?? ''));

        if ($status !== 'completed') {
            return WebhookProcessingResult::ignored(
                $provider,
                $externalId,
                sprintf('Webhook status [%s] does not credit a wallet.', $status !== '' ? $status : 'missing'),
            );
        }

        $userId = $payload['user_id'] ?? null;
        $amount = $payload['amount'] ?? null;
        $currencyCode = strtoupper((string) ($payload['currency'] ?? ''));

        if (! is_numeric($userId) || (! is_string($amount) && ! is_numeric($amount)) || $currencyCode === '') {
            return WebhookProcessingResult::failed($provider, $externalId, 'The webhook payload is incomplete.');
        }

        $currency = Currency::tryFrom($currencyCode);

        if ($currency === null) {
            return WebhookProcessingResult::failed($provider, $externalId, sprintf('Unknown currency [%s].', $currencyCode));
        }

        $user = User::query()->find((int) $userId);

        if (! $user instanceof User) {
            return WebhookProcessingResult::failed($provider, $externalId, 'The webhook names a member that does not exist.');
        }

        $idempotencyKey = sprintf('webhook:%s:%s', $provider, $externalId);

        $existing = FinancialTransaction::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing instanceof FinancialTransaction) {
            return WebhookProcessingResult::replay($provider, $externalId, $existing);
        }

        try {
            $wallet = $this->wallets->getOrCreateWallet($user, $currency);

            $transaction = $this->wallets->credit(
                $wallet,
                Money::of((string) $amount, $currency),
                FinancialTransactionType::Deposit,
                $idempotencyKey,
                [
                    'description' => sprintf('Payment webhook credit (%s)', $provider),
                    'metadata' => [
                        'source' => 'payment_webhook',
                        'provider' => $provider,
                        'external_id' => $externalId,
                    ],
                ],
            );
        } catch (Throwable $e) {
            return WebhookProcessingResult::failed($provider, $externalId, $e->getMessage());
        }

        WebhookReceipt::query()->firstOrCreate(
            ['nonce' => hash('sha256', $provider.'|'.$externalId)],
            [
                'gateway' => $provider,
                'signature_timestamp' => now()->getTimestamp(),
                'status' => WebhookReceipt::STATUS_ACCEPTED,
                'received_at' => now(),
            ],
        );

        return WebhookProcessingResult::applied($provider, $externalId, $transaction);
    }
}
