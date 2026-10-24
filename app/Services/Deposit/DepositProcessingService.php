<?php

declare(strict_types=1);

namespace App\Services\Deposit;

use App\Models\DepositTransaction;
use App\Models\LedgerTransaction;
use App\Services\Broadcast\RealtimeSyncService;
use App\Services\Ledger\DoubleEntryLedgerService;
use App\Services\Lock\DistributedLockService;
use App\Services\Payment\PaymentLogSanitizer;
use App\Services\Wallet\WalletMutationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Ingestion and settlement engine for external payment gateways, crypto deposits, and bank wires.
 */
class DepositProcessingService
{
    public function __construct(
        private readonly DistributedLockService $lockService,
        private readonly WalletMutationService $walletMutationService,
        private readonly DoubleEntryLedgerService $ledgerService,
        private readonly RealtimeSyncService $realtimeSyncService
    ) {}

    /**
     * Process an incoming confirmed deposit with double-entry ledger settlement.
     */
    public function processConfirmedDeposit(
        int|string $userId,
        string $amount,
        string $gatewayProvider,
        string $gatewayReferenceId,
        string $currency = 'THB',
        string $feeAmount = '0.0000',
        ?array $rawPayload = null
    ): DepositTransaction {
        if (bccomp($amount, '0.0000', 4) <= 0) {
            throw new InvalidArgumentException("Deposit amount must be positive: {$amount}");
        }

        $userIdStr = (string) $userId;
        $normalizedCurrency = strtoupper(trim($currency));
        $idempotencyKey = "DEP-{$gatewayProvider}-{$gatewayReferenceId}";
        $sanitizedPayload = PaymentLogSanitizer::sanitize($rawPayload);

        return $this->lockService->runWithLock("deposit:user:{$userIdStr}", function () use (
            $userIdStr,
            $amount,
            $gatewayProvider,
            $gatewayReferenceId,
            $normalizedCurrency,
            $feeAmount,
            $sanitizedPayload,
            $idempotencyKey
        ) {
            return DB::transaction(function () use (
                $userIdStr,
                $amount,
                $gatewayProvider,
                $gatewayReferenceId,
                $normalizedCurrency,
                $feeAmount,
                $sanitizedPayload,
                $idempotencyKey
            ) {
                $existing = DepositTransaction::where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof DepositTransaction) {
                    $sameTerms = (string) $existing->user_id === $userIdStr
                        && strtoupper((string) $existing->currency) === $normalizedCurrency
                        && bccomp((string) $existing->amount, $amount, 4) === 0
                        && bccomp((string) $existing->fee_amount, $feeAmount, 4) === 0;

                    if (! $sameTerms) {
                        throw new InvalidArgumentException(sprintf(
                            'Idempotency key [%s] is already bound to different deposit terms.',
                            $idempotencyKey,
                        ));
                    }

                    if ($existing->isCompleted()) {
                        return $existing;
                    }
                }

                $netAmount = bcsub($amount, $feeAmount, 4);
                if (bccomp($netAmount, '0.0000', 4) <= 0) {
                    throw new InvalidArgumentException('Net deposit amount cannot be non-positive after fees.');
                }

                $depositUuid = Str::uuid()->toString();
                $depositNumber = 'DEP-'.date('Ymd').'-'.strtoupper(Str::random(10));

                $this->walletMutationService->credit(
                    userId: $userIdStr,
                    amount: $netAmount,
                    referenceType: 'DEPOSIT_SETTLEMENT',
                    referenceId: $depositUuid,
                    currency: $normalizedCurrency,
                    walletType: 'main',
                    idempotencyKey: "WALLET-{$idempotencyKey}",
                    description: "Confirmed deposit from {$gatewayProvider} ref: {$gatewayReferenceId}"
                );

                $ledgerTx = LedgerTransaction::where('idempotency_key', "WALLET-{$idempotencyKey}")->firstOrFail();

                return DepositTransaction::create([
                    'id' => $depositUuid,
                    'user_id' => $userIdStr,
                    'deposit_number' => $depositNumber,
                    'gateway_provider' => $gatewayProvider,
                    'gateway_reference_id' => $gatewayReferenceId,
                    'amount' => $amount,
                    'fee_amount' => $feeAmount,
                    'net_amount' => $netAmount,
                    'currency' => $normalizedCurrency,
                    'status' => 'COMPLETED',
                    'idempotency_key' => $idempotencyKey,
                    'ledger_transaction_id' => $ledgerTx->id,
                    'gateway_raw_payload' => $sanitizedPayload,
                    'completed_at' => now(),
                ]);
            }, attempts: 3);
        });
    }
}
