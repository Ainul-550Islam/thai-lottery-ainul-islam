<?php

declare(strict_types=1);

namespace App\Services\Deposit;

use App\Models\DepositTransaction;
use App\Models\LedgerTransaction;
use App\Services\Broadcast\RealtimeSyncService;
use App\Services\Ledger\DoubleEntryLedgerService;
use App\Services\Lock\DistributedLockService;
use App\Services\Wallet\WalletMutationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Class DepositProcessingService
 *
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
        $idempotencyKey = "DEP-{$gatewayProvider}-{$gatewayReferenceId}";

        return $this->lockService->runWithLock("deposit:user:{$userIdStr}", function () use (
            $userIdStr,
            $amount,
            $gatewayProvider,
            $gatewayReferenceId,
            $currency,
            $feeAmount,
            $rawPayload,
            $idempotencyKey
        ) {
            return DB::transaction(function () use (
                $userIdStr,
                $amount,
                $gatewayProvider,
                $gatewayReferenceId,
                $currency,
                $feeAmount,
                $rawPayload,
                $idempotencyKey
            ) {
                // Idempotency check: prevent duplicate deposit credits.
                // A replay is only a replay when every invariant term still
                // matches: the same provider notification arriving twice with
                // a different amount or currency is a conflict, not a retry,
                // and must never be silently accepted (or, worse, credited).
                $existing = DepositTransaction::where('idempotency_key', $idempotencyKey)->first();
                if ($existing && $existing->isCompleted()) {
                    $termsDiffer = (string) $existing->user_id !== $userIdStr
                        || (string) $existing->gateway_provider !== $gatewayProvider
                        || (string) $existing->gateway_reference_id !== $gatewayReferenceId
                        || (string) $existing->currency !== $currency
                        || bccomp((string) $existing->amount, $amount, 4) !== 0
                        || bccomp((string) $existing->fee_amount, $feeAmount, 4) !== 0;

                    if ($termsDiffer) {
                        throw new InvalidArgumentException(sprintf(
                            'Webhook terms conflict for gateway reference [%s]: the recorded deposit does not match the replayed notification.',
                            $gatewayReferenceId,
                        ));
                    }

                    return $existing;
                }

                $netAmount = bcsub($amount, $feeAmount, 4);
                if (bccomp($netAmount, '0.0000', 4) <= 0) {
                    throw new InvalidArgumentException('Net deposit amount cannot be non-positive after fees.');
                }

                $depositUuid = Str::uuid()->toString();
                $depositNumber = 'DEP-'.date('Ymd').'-'.strtoupper(Str::random(10));

                // Atomic Wallet Balance Increment + Single Balanced Double-Entry Ledger Posting
                $this->walletMutationService->credit(
                    userId: $userIdStr,
                    amount: $netAmount,
                    referenceType: 'DEPOSIT_SETTLEMENT',
                    referenceId: $depositUuid,
                    currency: $currency,
                    walletType: 'main',
                    idempotencyKey: "WALLET-{$idempotencyKey}",
                    description: "Confirmed deposit from {$gatewayProvider} ref: {$gatewayReferenceId}"
                );

                $ledgerTx = LedgerTransaction::where('idempotency_key', "WALLET-{$idempotencyKey}")->firstOrFail();

                // Persist Deposit Record
                return DepositTransaction::create([
                    'id' => $depositUuid,
                    'user_id' => $userIdStr,
                    'deposit_number' => $depositNumber,
                    'gateway_provider' => $gatewayProvider,
                    'gateway_reference_id' => $gatewayReferenceId,
                    'amount' => $amount,
                    'fee_amount' => $feeAmount,
                    'net_amount' => $netAmount,
                    'currency' => $currency,
                    'status' => 'COMPLETED',
                    'idempotency_key' => $idempotencyKey,
                    'ledger_transaction_id' => $ledgerTx->id,
                    'gateway_raw_payload' => $rawPayload,
                    'completed_at' => now(),
                ]);
            }, attempts: 3);
        });
    }
}
