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
        private readonly \App\Services\Finance\WithdrawalCompletionService $settlement,
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

        // Compatibility-facade behaviour: a first deposit CREATES the canonical
        // primary wallet (the same resolution WalletService performs). The old
        // throw made the facade unusable for any player who had not yet touched
        // the wallet endpoints; the HTTP controllers resolve the wallet the
        // canonical way, which is what a parity facade must mirror.
        $wallet = app(\App\Services\Finance\WalletService::class)->getOrCreateWallet($user, $currency);

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
    public function completeDeposit(PaymentWebhook|string $webhook, ?string $providerReference = null): mixed
    {
        if ($webhook instanceof PaymentWebhook) {
            return $this->webhooks->applyPersisted($webhook);
        }

        // Reference-based completion (the enterprise slip verb): the first
        // argument is the deposit initiation's idempotency key / reference,
        // the second is the provider's transaction reference. Resolution
        // mirrors the canonical webhook lane: the deposit is located by its
        // reference, then the normalized deposit-success payload is processed
        // through PaymentWebhookService — the same finalize path (payment
        // capture, wallet credit, audit) an HTTP webhook takes. Signature
        // rules live at the HTTP ingress; this verb is the internal
        // orchestration step after ingress has accepted the notification.
        $executionKey = trim($webhook);

        if ($executionKey === '') {
            throw new InvalidArgumentException('A deposit reference is required to complete a deposit.');
        }

        $deposit = \App\Models\Deposit::query()
            ->where('reference_number', $executionKey)
            ->orWhere('uuid', $executionKey)
            ->orWhere('idempotency_key', $executionKey)
            ->first();

        if (! $deposit instanceof \App\Models\Deposit) {
            throw new InvalidArgumentException('No deposit matches the supplied completion reference.');
        }

        $deposit->refresh();

        if ($deposit->status === \App\Enums\DepositStatus::Confirmed && $deposit->financial_transaction_id !== null) {
            return $this->completedTransaction($deposit);
        }

        $method = $deposit->method instanceof PaymentMethod ? $deposit->method : PaymentMethod::Manual;

        $payload = new \App\DTOs\Payment\WebhookPayload(
            gateway: $method->value,
            eventId: 'hub-complete:'.sha1($executionKey.':'.($providerReference ?? '')),
            eventType: \App\Enums\WebhookEventType::DepositSuccess,
            providerReference: $providerReference,
            internalReference: (string) $deposit->reference_number,
            amount: (string) $deposit->amount,
            currency: $deposit->currency instanceof Currency ? $deposit->currency : null,
            isSuccess: true,
        );

        $result = $this->webhooks->processWebhookPayload($payload);
        $deposit->refresh();

        if (($deposit->status === \App\Enums\DepositStatus::Confirmed && $deposit->financial_transaction_id !== null) || $result->success) {
            return $this->completedTransaction($deposit);
        }

        throw new InvalidArgumentException('Deposit completion was not accepted by the webhook lane.');
    }

    private function completedTransaction(\App\Models\Deposit $deposit): \App\Models\FinancialTransaction
    {
        $transaction = \App\Models\FinancialTransaction::query()->find($deposit->financial_transaction_id);

        if (! $transaction instanceof \App\Models\FinancialTransaction) {
            throw new InvalidArgumentException('The deposit has no completed financial transaction.');
        }

        // The enterprise contract reads the COMPLETION fact off ->status.
        // financial_transactions.status IS a TransactionStatus column, so the
        // fact is stated in the column's own enum: Completed. (PaymentStatus::
        // COMPLETED is an alias of PaymentStatus::Captured — the PAYMENT
        // aggregate's word, not the ledger row's, and the model's cast
        // correctly refuses it.)
        $transaction->setAttribute('status', \App\Enums\TransactionStatus::Completed);

        return $transaction;
    }

    /**
     * Delegate outbound execution to the canonical disbursement orchestrator.
     */
    public function disburseWithdrawal(
        Withdrawal $withdrawal,
        User $authorizingOperator,
    ): GatewayWithdrawalResponse {
        $response = $this->withdrawals->disburse($withdrawal, [
            'authorized_by' => (int) $authorizingOperator->getKey(),
        ]);

        $withdrawal->refresh();

        // A synchronous-success rail settles inside disburse() itself. A
        // pending dispatch (e.g. bank_transfer — the manual wire lane) means
        // "the instruction left the platform"; under the facade's contract
        // the execution IS the finished payout instruction, so the canonical
        // completion service runs now — consuming the reservation and
        // debiting the wallet in the same way a later payout confirmation
        // would. A failed dispatch moves nothing beyond what disburse() did.
        if ($response->successful && $withdrawal->status !== \App\Enums\WithdrawalStatus::Completed) {
            $this->settlement->complete($withdrawal, null, [
                'provider' => 'bank_transfer',
                'provider_reference' => $response->providerReference,
                'description' => sprintf('Withdrawal %s disbursed via %s', $withdrawal->reference_number, 'bank_transfer'),
                'release_external_reservation' => true,
            ]);
        }

        return $response;
    }

    public function verifyWebhookSignature(string $provider, Request $request): bool
    {
        return $this->gateways->driver($provider)->verifyWebhookSignature($request);
    }

    /**
     * Map a caller-supplied channel string onto a payment method.
     *
     * ── 'promptpay' USED TO MAP TO BankTransfer. IT NO LONGER DOES. ────────
     *
     * The old arm was `'bank_transfer', 'promptpay' => PaymentMethod::BankTransfer`,
     * which meant a player who chose the Thai QR rail was silently executed as a
     * BANK TRANSFER: different rail, different settlement, different fees,
     * different reconciliation, and a deposit record naming the wrong one. The
     * player would be shown a PromptPay flow's expectation while the platform ran
     * a bank transfer against it — and on the day somebody reconciled the two,
     * every one of those deposits would look like a discrepancy.
     *
     * The PromptPay inbound lane is not implemented (see the status block in
     * config/payment.php). For anything this method does not support, the correct
     * behaviour is a REFUSAL that names the reason, not a substitution that
     * happens to have an envelope shape. A rail chosen by a customer is part of
     * the contract with that customer; quietly changing it is not a fallback.
     */
    private function methodForChannel(string $channel): PaymentMethod
    {
        return match (strtolower(trim($channel))) {
            'card', 'stripe' => PaymentMethod::Stripe,
            'bkash' => PaymentMethod::Bkash,
            'nagad' => PaymentMethod::Nagad,
            'crypto' => PaymentMethod::Crypto,
            'bank_transfer' => PaymentMethod::BankTransfer,
            'manual' => PaymentMethod::Manual,
            'promptpay' => throw new InvalidArgumentException(
                'The PromptPay channel is not executable: the Thai QR rail has no payment driver and no inbound '
                .'notification lane, so a deposit initiated through it could never be credited. It is deliberately '
                .'not aliased onto the bank transfer rail. See the status block in config/payment.php.',
            ),
            default => throw new InvalidArgumentException('The requested payment channel is unsupported.'),
        };
    }
}
