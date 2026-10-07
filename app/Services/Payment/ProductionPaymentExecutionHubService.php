<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\DTOs\Payment\GatewayWithdrawalResponse;
use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Models\PaymentWebhook;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Finance\Money;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Compatibility facade over the canonical payment services.
 *
 * This class owns no provider protocol, checkout synthesis, callback parsing,
 * wallet mutation or settlement state. Every operation delegates to the same
 * gateway, initiation, webhook and disbursement services used by the canonical
 * HTTP endpoints.
 */
final class ProductionPaymentExecutionHubService
{
    public function __construct(
        private readonly PaymentInitiationService $initiations,
        private readonly PaymentWebhookService $webhooks,
        private readonly WithdrawalDisbursementService $withdrawals,
        private readonly PaymentGatewayManager $gateways,
    ) {}

    /**
     * @param  array{amount:string, channel:string, currency?:string}  $params
     * @return array{payment_id:int, reference_id:string, redirect_url:string|null, status:string}
     */
    public function initiateDeposit(User $user, array $params, string $idempotencyKey): array
    {
        $amount = trim((string) ($params['amount'] ?? ''));
        $currency = Currency::tryFrom(strtoupper((string) ($params['currency'] ?? Currency::THB->value)));
        $method = $this->methodForChannel((string) ($params['channel'] ?? ''));

        if ($currency === null) {
            throw new InvalidArgumentException('The requested currency is unsupported.');
        }

        if (preg_match('/^\d+(?:\.\d{1,2})?$/', $amount) !== 1 || bccomp($amount, '0.00', 2) <= 0) {
            throw new InvalidArgumentException('Deposit amount must be a positive decimal string.');
        }

        $wallet = Wallet::query()
            ->where('user_id', (int) $user->getKey())
            ->where('currency', $currency->value)
            ->first();

        if (! $wallet instanceof Wallet) {
            throw new InvalidArgumentException('The account has no wallet for the requested currency.');
        }

        $result = $this->initiations->initiateDeposit(
            wallet: $wallet,
            amount: Money::of($amount, $currency),
            method: $method,
            idempotencyKey: $idempotencyKey,
        );

        return [
            'payment_id' => (int) $result['payment']->getKey(),
            'reference_id' => (string) $result['deposit']->reference_number,
            'redirect_url' => $result['gateway_response']->redirectUrl,
            'status' => $result['payment']->status->value,
        ];
    }

    /**
     * Apply only a persisted, signature-verified webhook envelope.
     */
    public function completeDeposit(PaymentWebhook $webhook): PaymentWebhook
    {
        return $this->webhooks->applyPersisted($webhook);
    }

    /**
     * Delegate outbound execution to the canonical disbursement orchestrator.
     */
    public function disburseWithdrawal(
        Withdrawal $withdrawal,
        User $authorizingOperator,
    ): GatewayWithdrawalResponse {
        return $this->withdrawals->disburse($withdrawal, [
            'authorized_by' => (int) $authorizingOperator->getKey(),
        ]);
    }

    public function verifyWebhookSignature(string $provider, Request $request): bool
    {
        return $this->gateways->driver($provider)->verifyWebhookSignature($request);
    }

    private function methodForChannel(string $channel): PaymentMethod
    {
        return match (strtolower(trim($channel))) {
            'card', 'stripe' => PaymentMethod::Stripe,
            'bkash' => PaymentMethod::Bkash,
            'nagad' => PaymentMethod::Nagad,
            'crypto' => PaymentMethod::Crypto,
            'bank_transfer', 'promptpay' => PaymentMethod::BankTransfer,
            'manual' => PaymentMethod::Manual,
            default => throw new InvalidArgumentException('The requested payment channel is unsupported.'),
        };
    }
}
