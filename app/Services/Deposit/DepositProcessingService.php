<?php

declare(strict_types=1);

namespace App\Services\Deposit;

use App\Models\DepositTransaction;
use App\Services\Broadcast\RealtimeSyncService;
use App\Services\Ledger\DoubleEntryLedgerService;
use App\Services\Lock\DistributedLockService;
use App\Services\Wallet\WalletMutationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

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
        string $userId,
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

        $idempotencyKey = "DEP-{$gatewayProvider}-{$gatewayReferenceId}";

        return $this->lockService->runWithLock("deposit:user:{$userId}", function () use (
            $userId,
            $amount,
            $gatewayProvider,
            $gatewayReferenceId,
            $currency,
            $feeAmount,
            $rawPayload,
            $idempotencyKey
        ) {
            return DB::transaction(function () use (
                $userId,
                $amount,
                $gatewayProvider,
                $gatewayReferenceId,
                $currency,
                $feeAmount,
                $rawPayload,
                $idempotencyKey
            ) {
                // Idempotency check: prevent duplicate deposit credits
                $existing = DepositTransaction::where('idempotency_key', $idempotencyKey)->first();
                if ($existing && $existing->isCompleted()) {
                    return $existing;
                }

                $netAmount = bcsub($amount, $feeAmount, 4);
                if (bccomp($netAmount, '0.0000', 4) <= 0) {
                    throw new InvalidArgumentException('Net deposit amount cannot be non-positive after fees.');
                }

                $depositUuid = Str::uuid()->toString();
                $depositNumber = 'DEP-' . date('Ymd') . '-' . strtoupper(Str::random(10));

                // 1. Post to Double-Entry Ledger:
                // Debit: Gateway Clearing Asset Account (Money in bank)
                // Credit: User Liability Account (User's balance in system)
                $gatewayAssetAccount = $this->ledgerService->getSystemAccount(
                    accountNumber: "SYS-ASSET-GATEWAY-{$gatewayProvider}-{$currency}",
                    name: "Gateway Settlement Asset [{$gatewayProvider}]",
                    type: 'ASSET',
                    currency: $currency
                );
                $userLiabilityAccount = $this->ledgerService->getOrCreateUserAccount($userId, $currency);

                $ledgerTx = $this->ledgerService->postTransaction(
                    referenceType: 'DEPOSIT_SETTLEMENT',
                    referenceId: $depositUuid,
                    currency: $currency,
                    entries: [
                        [
                            'account_id' => $gatewayAssetAccount->id,
                            'direction' => 'DEBIT',
                            'amount' => $netAmount,
                        ],
                        [
                            'account_id' => $userLiabilityAccount->id,
                            'direction' => 'CREDIT',
                            'amount' => $netAmount,
                        ],
                    ],
                    idempotencyKey: $idempotencyKey,
                    description: "Confirmed deposit from {$gatewayProvider} ref: {$gatewayReferenceId}"
                );

                // 2. Direct Atomic Wallet Balance Increment
                $wallet = $this->walletMutationService->credit(
                    userId: $userId,
                    amount: $netAmount,
                    referenceType: 'DEPOSIT',
                    referenceId: $depositUuid,
                    currency: $currency,
                    walletType: 'main',
                    idempotencyKey: "WALLET-{$idempotencyKey}",
                    description: "Deposit credited via {$gatewayProvider}"
                );

                // 3. Persist Deposit Record
                $deposit = DepositTransaction::create([
                    'id' => $depositUuid,
                    'user_id' => $userId,
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

                return $deposit;
            }, attempts: 3);
        });
    }
}
