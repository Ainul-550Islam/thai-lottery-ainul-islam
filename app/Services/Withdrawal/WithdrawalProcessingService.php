<?php

declare(strict_types=1);

namespace App\Services\Withdrawal;

use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use App\Services\Broadcast\RealtimeSyncService;
use App\Services\Ledger\DoubleEntryLedgerService;
use App\Services\Lock\DistributedLockService;
use App\Services\Wallet\WalletMutationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Class WithdrawalProcessingService
 *
 * Implements a 4-eyes approval workflow, locked-balance escrow, and double-entry disbursement settlement.
 */
class WithdrawalProcessingService
{
    private const AUTO_APPROVAL_THRESHOLD_THB = '10000.0000';

    public function __construct(
        private readonly DistributedLockService $lockService,
        private readonly WalletMutationService $walletMutationService,
        private readonly DoubleEntryLedgerService $ledgerService,
        private readonly RealtimeSyncService $realtimeSyncService
    ) {}

    /**
     * Create withdrawal request and immediately lock funds in user wallet.
     */
    public function requestWithdrawal(
        string $userId,
        string $amount,
        string $payoutChannel,
        string $accountName,
        string $accountNumber,
        ?string $bankCode = null,
        string $currency = 'THB'
    ): WithdrawalRequest {
        if (bccomp($amount, '100.0000', 4) < 0) {
            throw new InvalidArgumentException("Minimum withdrawal is 100.0000 THB. Requested: {$amount}");
        }

        $idempotencyKey = 'WTH-' . Str::uuid()->toString();

        return $this->lockService->runWithLock("wallet:user:{$userId}", function () use (
            $userId,
            $amount,
            $payoutChannel,
            $accountName,
            $accountNumber,
            $bankCode,
            $currency,
            $idempotencyKey
        ) {
            return DB::transaction(function () use (
                $userId,
                $amount,
                $payoutChannel,
                $accountName,
                $accountNumber,
                $bankCode,
                $currency,
                $idempotencyKey
            ) {
                /** @var Wallet $wallet */
                $wallet = Wallet::where('user_id', $userId)
                    ->where('type', 'main')
                    ->where('currency', $currency)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (bccomp($wallet->available_balance, $amount, 4) < 0) {
                    throw new RuntimeException("Insufficient available balance for withdrawal: {$wallet->available_balance}");
                }

                // Escrow: Increment locked_balance
                $oldVersion = $wallet->version;
                $newLockedBalance = bcadd($wallet->locked_balance, $amount, 4);

                DB::table('wallets')
                    ->where('id', $wallet->id)
                    ->where('version', $oldVersion)
                    ->update([
                        'locked_balance' => $newLockedBalance,
                        'version' => $oldVersion + 1,
                        'updated_at' => now(),
                    ]);

                $wallet->refresh();

                $withdrawal = WithdrawalRequest::create([
                    'id' => Str::uuid()->toString(),
                    'user_id' => $userId,
                    'withdrawal_number' => 'WTH-' . date('Ymd') . '-' . strtoupper(Str::random(10)),
                    'payout_channel' => $payoutChannel,
                    'account_name' => $accountName,
                    'account_number' => $accountNumber,
                    'bank_code' => $bankCode,
                    'amount' => $amount,
                    'fee_amount' => '0.0000',
                    'net_disbursement_amount' => $amount,
                    'currency' => $currency,
                    'status' => 'PENDING_APPROVAL',
                    'idempotency_key' => $idempotencyKey,
                ]);

                $this->realtimeSyncService->syncWalletBalance(
                    userId: $userId,
                    walletType: 'main',
                    currency: $currency,
                    balance: $wallet->balance,
                    availableBalance: $wallet->available_balance,
                    version: $wallet->version,
                    reason: 'WITHDRAWAL_REQUEST'
                );

                return $withdrawal;
            });
        });
    }

    /**
     * Disburse approved withdrawal with Double-Entry Ledger and deduct locked wallet funds.
     */
    public function disburseWithdrawal(string $withdrawalId, string $operatorId): WithdrawalRequest
    {
        return DB::transaction(function () use ($withdrawalId, $operatorId) {
            /** @var WithdrawalRequest $withdrawal */
            $withdrawal = WithdrawalRequest::where('id', $withdrawalId)->lockForUpdate()->firstOrFail();

            if (!in_array($withdrawal->status, ['PENDING_APPROVAL', 'APPROVED'], true)) {
                throw new RuntimeException("Withdrawal [{$withdrawal->id}] is in invalid status: {$withdrawal->status}");
            }

            $userId = $withdrawal->user_id;
            $amount = $withdrawal->amount;
            $currency = $withdrawal->currency;

            /** @var Wallet $wallet */
            $wallet = Wallet::where('user_id', $userId)
                ->where('type', 'main')
                ->where('currency', $currency)
                ->lockForUpdate()
                ->firstOrFail();

            // Deduct both balance and locked_balance
            $newBalance = bcsub($wallet->balance, $amount, 4);
            $newLocked = bcsub($wallet->locked_balance, $amount, 4);

            DB::table('wallets')
                ->where('id', $wallet->id)
                ->where('version', $wallet->version)
                ->update([
                    'balance' => $newBalance,
                    'locked_balance' => $newLocked,
                    'version' => $wallet->version + 1,
                    'updated_at' => now(),
                ]);

            $wallet->refresh();

            // Post to Double-Entry Ledger:
            // Debit: User Liability Account (Decrease system debt to user)
            // Credit: Bank Clearing Asset Account (Decrease physical cash in bank)
            $userLiabilityAccount = $this->ledgerService->getOrCreateUserAccount($userId, $currency);
            $bankAssetAccount = $this->ledgerService->getSystemAccount(
                accountNumber: "SYS-ASSET-BANK-{$withdrawal->payout_channel}-{$currency}",
                name: "Bank Disbursement Asset [{$withdrawal->payout_channel}]",
                type: 'ASSET',
                currency: $currency
            );

            $ledgerTx = $this->ledgerService->postTransaction(
                referenceType: 'WITHDRAWAL_DISBURSEMENT',
                referenceId: $withdrawal->id,
                currency: $currency,
                entries: [
                    [
                        'account_id' => $userLiabilityAccount->id,
                        'direction' => 'DEBIT',
                        'amount' => $amount,
                    ],
                    [
                        'account_id' => $bankAssetAccount->id,
                        'direction' => 'CREDIT',
                        'amount' => $amount,
                    ],
                ],
                idempotencyKey: "LEDGER-WTH-{$withdrawal->id}",
                description: "Withdrawal disbursement via {$withdrawal->payout_channel} to {$withdrawal->account_number}"
            );

            $withdrawal->update([
                'status' => 'COMPLETED',
                'approved_by_operator_id' => $operatorId,
                'disbursed_by_operator_id' => $operatorId,
                'ledger_transaction_id' => $ledgerTx->id,
                'disbursed_at' => now(),
            ]);

            $this->realtimeSyncService->syncWalletBalance(
                userId: $userId,
                walletType: 'main',
                currency: $currency,
                balance: $wallet->balance,
                availableBalance: $wallet->available_balance,
                version: $wallet->version,
                reason: 'WITHDRAWAL_COMPLETED'
            );

            return $withdrawal;
        });
    }

    /**
     * Reject withdrawal and release locked balance back to user's available funds.
     */
    public function rejectWithdrawal(string $withdrawalId, string $operatorId, string $reason): WithdrawalRequest
    {
        return DB::transaction(function () use ($withdrawalId, $operatorId, $reason) {
            /** @var WithdrawalRequest $withdrawal */
            $withdrawal = WithdrawalRequest::where('id', $withdrawalId)->lockForUpdate()->firstOrFail();

            if ($withdrawal->status !== 'PENDING_APPROVAL') {
                throw new RuntimeException("Cannot reject withdrawal in status: {$withdrawal->status}");
            }

            /** @var Wallet $wallet */
            $wallet = Wallet::where('user_id', $withdrawal->user_id)
                ->where('type', 'main')
                ->where('currency', $withdrawal->currency)
                ->lockForUpdate()
                ->firstOrFail();

            $newLocked = bcsub($wallet->locked_balance, $withdrawal->amount, 4);

            DB::table('wallets')
                ->where('id', $wallet->id)
                ->where('version', $wallet->version)
                ->update([
                    'locked_balance' => $newLocked,
                    'version' => $wallet->version + 1,
                    'updated_at' => now(),
                ]);

            $wallet->refresh();

            $withdrawal->update([
                'status' => 'REJECTED',
                'approved_by_operator_id' => $operatorId,
                'rejection_reason' => $reason,
            ]);

            $this->realtimeSyncService->syncWalletBalance(
                userId: $withdrawal->user_id,
                walletType: 'main',
                currency: $withdrawal->currency,
                balance: $wallet->balance,
                availableBalance: $wallet->available_balance,
                version: $wallet->version,
                reason: 'WITHDRAWAL_REJECTED'
            );

            return $withdrawal;
        });
    }
}
