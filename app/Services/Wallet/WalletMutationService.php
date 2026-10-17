<?php

declare(strict_types=1);

namespace App\Services\Wallet;

use App\Models\Wallet;
use App\Services\Broadcast\RealtimeSyncService;
use App\Services\Ledger\DoubleEntryLedgerService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Class WalletMutationService
 *
 * Enforces transaction-bound, version-incremented, pessimistic-locked wallet operations
 * with strict BCMath decimal precision and zero mass-assignment.
 */
class WalletMutationService
{
    public function __construct(
        private readonly DoubleEntryLedgerService $ledgerService,
        private readonly RealtimeSyncService $realtimeSyncService
    ) {}

    /**
     * Deduct funds from a user's wallet with pessimistic locking and double-entry posting.
     */
    public function debit(
        string $userId,
        string $amount,
        string $referenceType,
        string $referenceId,
        string $currency = 'THB',
        string $walletType = 'main',
        ?string $idempotencyKey = null,
        ?string $description = null
    ): Wallet {
        if (bccomp($amount, '0.0000', 4) <= 0) {
            throw new InvalidArgumentException("Debit amount must be strictly positive: {$amount}");
        }

        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('Wallet operations must run inside an active database transaction.');
        }

        /** @var Wallet $wallet */
        $wallet = Wallet::where('user_id', $userId)
            ->where('type', $walletType)
            ->where('currency', $currency)
            ->lockForUpdate()
            ->firstOrFail();

        if (!$wallet->isActive()) {
            throw new RuntimeException("Wallet [{$wallet->id}] is not active (status: {$wallet->status}).");
        }

        $available = $wallet->available_balance;
        if (bccomp($available, $amount, 4) < 0) {
            throw new RuntimeException(
                "Insufficient funds for user [{$userId}]. Available: {$available} {$currency}, Required: {$amount} {$currency}."
            );
        }

        $oldVersion = $wallet->version;
        $newBalance = bcsub($wallet->balance, $amount, 4);

        // Optimistic check + Atomic increment
        $affectedRows = DB::table('wallets')
            ->where('id', $wallet->id)
            ->where('version', $oldVersion)
            ->update([
                'balance' => $newBalance,
                'version' => $oldVersion + 1,
                'updated_at' => now(),
            ]);

        if ($affectedRows !== 1) {
            throw new RuntimeException("Wallet concurrency version conflict on wallet [{$wallet->id}].");
        }

        $wallet->refresh();

        // Double-Entry Ledger Posting
        $userLiabilityAccount = $this->ledgerService->getOrCreateUserAccount($userId, $currency);
        $systemRevenueAccount = $this->ledgerService->getSystemAccount(
            accountNumber: "SYS-REV-{$currency}",
            name: "System Pool / Revenue Account [{$currency}]",
            type: 'REVENUE',
            currency: $currency
        );

        // Debit User Liability (decreases user's claim), Credit System Revenue
        $this->ledgerService->postTransaction(
            referenceType: $referenceType,
            referenceId: $referenceId,
            currency: $currency,
            entries: [
                [
                    'account_id' => $userLiabilityAccount->id,
                    'direction' => 'DEBIT',
                    'amount' => $amount,
                ],
                [
                    'account_id' => $systemRevenueAccount->id,
                    'direction' => 'CREDIT',
                    'amount' => $amount,
                ],
            ],
            idempotencyKey: $idempotencyKey,
            description: $description ?? "Wallet debit for {$referenceType} #{$referenceId}"
        );

        // Realtime WebSocket balance broadcast
        $this->realtimeSyncService->syncWalletBalance(
            userId: $userId,
            walletType: $walletType,
            currency: $currency,
            balance: $wallet->balance,
            availableBalance: $wallet->available_balance,
            version: $wallet->version,
            reason: $referenceType
        );

        return $wallet;
    }

    /**
     * Credit funds to a user's wallet with pessimistic locking and double-entry posting.
     */
    public function credit(
        string $userId,
        string $amount,
        string $referenceType,
        string $referenceId,
        string $currency = 'THB',
        string $walletType = 'main',
        ?string $idempotencyKey = null,
        ?string $description = null
    ): Wallet {
        if (bccomp($amount, '0.0000', 4) <= 0) {
            throw new InvalidArgumentException("Credit amount must be strictly positive: {$amount}");
        }

        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('Wallet operations must run inside an active database transaction.');
        }

        /** @var Wallet $wallet */
        $wallet = Wallet::where('user_id', $userId)
            ->where('type', $walletType)
            ->where('currency', $currency)
            ->lockForUpdate()
            ->firstOrFail();

        if (!$wallet->isActive()) {
            throw new RuntimeException("Wallet [{$wallet->id}] is not active (status: {$wallet->status}).");
        }

        $oldVersion = $wallet->version;
        $newBalance = bcadd($wallet->balance, $amount, 4);

        $affectedRows = DB::table('wallets')
            ->where('id', $wallet->id)
            ->where('version', $oldVersion)
            ->update([
                'balance' => $newBalance,
                'version' => $oldVersion + 1,
                'updated_at' => now(),
            ]);

        if ($affectedRows !== 1) {
            throw new RuntimeException("Wallet concurrency version conflict on wallet [{$wallet->id}].");
        }

        $wallet->refresh();

        // Double-Entry Ledger Posting
        $userLiabilityAccount = $this->ledgerService->getOrCreateUserAccount($userId, $currency);
        $systemPayoutAccount = $this->ledgerService->getSystemAccount(
            accountNumber: "SYS-EXP-PAYOUT-{$currency}",
            name: "System Prize Payout Expense Account [{$currency}]",
            type: 'EXPENSE',
            currency: $currency
        );

        // Debit System Expense, Credit User Liability (increases user's balance)
        $this->ledgerService->postTransaction(
            referenceType: $referenceType,
            referenceId: $referenceId,
            currency: $currency,
            entries: [
                [
                    'account_id' => $systemPayoutAccount->id,
                    'direction' => 'DEBIT',
                    'amount' => $amount,
                ],
                [
                    'account_id' => $userLiabilityAccount->id,
                    'direction' => 'CREDIT',
                    'amount' => $amount,
                ],
            ],
            idempotencyKey: $idempotencyKey,
            description: $description ?? "Wallet credit for {$referenceType} #{$referenceId}"
        );

        // Realtime WebSocket broadcast
        $this->realtimeSyncService->syncWalletBalance(
            userId: $userId,
            walletType: $walletType,
            currency: $currency,
            balance: $wallet->balance,
            availableBalance: $wallet->available_balance,
            version: $wallet->version,
            reason: $referenceType
        );

        return $wallet;
    }
}
