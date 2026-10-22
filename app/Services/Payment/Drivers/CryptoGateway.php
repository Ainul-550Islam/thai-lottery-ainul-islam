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
use Illuminate\Support\Facades\Log;

/**
 * Fail-closed boundary for the crypto rail until an actual provider is selected.
 *
 * This repository has no provider-specific invoice API, address assignment,
 * webhook signature contract, or payout API. Generating an application URL
 * that looks like checkout would falsely imply that a payment can be collected.
 * The driver therefore exposes no payable capability and refuses callbacks;
 * provider integration must be implemented against the contracted provider's
 * verified protocol before this rail can be enabled.
 */
class CryptoGateway extends AbstractPaymentGateway
{
    public function name(): string
    {
        return 'crypto';
    }

    public function label(): string
    {
        return 'Cryptocurrency';
    }

    public function status(): GatewayIntegrationStatus
    {
        return GatewayIntegrationStatus::Unsupported;
    }

    public function supportsDeposit(): bool
    {
        return false;
    }

    public function supportsWithdrawal(): bool
    {
        return false;
    }

    public function supportsCurrency(Currency $currency): bool
    {
        return false;
    }

    public function initiateDeposit(Deposit $deposit, array $options = []): GatewayDepositResponse
    {
        return GatewayDepositResponse::failed(
            'Crypto payments are unavailable because no verified provider integration is configured.',
            [],
            ['gateway' => $this->name(), 'state' => 'unsupported'],
        );
    }

    public function initiateWithdrawal(Withdrawal $withdrawal, array $options = []): GatewayWithdrawalResponse
    {
        return GatewayWithdrawalResponse::failed(
            'Crypto payouts are unavailable because no verified provider integration is configured.',
        );
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        Log::warning('Crypto webhook rejected: no provider-specific signature verifier is configured.');

        return false;
    }

    public function parseWebhook(Request $request): WebhookPayload
    {
        $data = $request->json()->all();

        $txId = (string) ($data['txid'] ?? $data['transaction_id'] ?? $data['id'] ?? '');
        $invoiceId = (string) ($data['invoice_id'] ?? $data['order_id'] ?? $data['reference'] ?? '');
        $amount = (string) ($data['amount'] ?? '0.00');
        $status = strtoupper((string) ($data['status'] ?? ''));

        $currency = isset($data['currency']) ? (Currency::tryFrom(strtoupper((string) $data['currency'])) ?? Currency::USD) : Currency::USD;

        $eventType = match ($status) {
            'CONFIRMED', 'COMPLETED', 'PAID', 'SUCCESS' => WebhookEventType::DepositSuccess,
            'EXPIRED' => WebhookEventType::DepositExpired,
            'FAILED', 'REJECTED' => WebhookEventType::DepositFailed,
            default => WebhookEventType::Unknown,
        };

        $eventId = ! empty($txId) ? 'crypto_'.$txId : 'crypto_evt_'.bin2hex(random_bytes(8));

        return new WebhookPayload(
            gateway: $this->name(),
            eventId: $eventId,
            eventType: $eventType,
            providerReference: ! empty($txId) ? $txId : $invoiceId,
            internalReference: ! empty($invoiceId) ? $invoiceId : null,
            amount: $amount,
            currency: $currency,
            isSuccess: $eventType->isSuccess(),
            failureReason: $eventType->isFailure() ? ($data['error'] ?? 'Crypto transaction failed.') : null,
            rawData: $data,
            metadata: [
                'txid' => $txId,
                'confirmations' => $data['confirmations'] ?? null,
            ],
        );
    }
}
