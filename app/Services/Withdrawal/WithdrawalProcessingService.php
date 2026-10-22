<?php

declare(strict_types=1);

namespace App\Services\Withdrawal;

use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use App\Services\Broadcast\RealtimeSyncService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService as CanonicalWalletService;
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
    public function __construct(
        private readonly DistributedLockService $lockService,
        private readonly WalletMutationService $walletMutationService,
        private readonly DoubleEntryLedgerService $ledgerService,
        private readonly RealtimeSyncService $realtimeSyncService,
        private readonly ?CanonicalWalletService $walletService = null,
    ) {}

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

        $idempotencyKey = 'WTH-'.Str::uuid()->toString();
        $finance = $this->canonicalWallets();
        $currencyEnum = Currency::from(strtoupper($currency));
        $scaledAmount = bcadd($amount, '0', 2);

        return $this->lockService->runWithLock("wallet:user:{$userId}", function () use (
            $userId,
            $amount,
            $scaledAmount,
            $payoutChannel,
            $accountName,
            $accountNumber,
            $bankCode,
            $currency,
            $currencyEnum,
            $idempotencyKey,
            $finance
        ) {
            return DB::transaction(function () use (
                $userId,
                $amount,
                $scaledAmount,
                $payoutChannel,
                $accountName,
                $accountNumber,
                $bankCode,
                $currency,
                $currencyEnum,
                $idempotencyKey,
                $finance
            ) {
                /** @var Wallet $wallet */
                $wallet = Wallet::where('user_id', $userId)
                    ->whereIn('type', ['main', 'primary'])
                    ->where('currency', $currency)
                    ->lockForUpdate()
                    ->firstOrFail();

                try {
                    $lockedWallet = $finance->lockFunds(
                        $wallet,
                        Money::of($scaledAmount, $currencyEnum),
                        'WITHDRAWAL_ESCROW',
                    );
                } catch (InsufficientBalanceException) {
                    $available = $finance->availableBalance($wallet)->toString();
                    throw new RuntimeException("Insufficient available balance for withdrawal: {$available}");
                }

                $withdrawal = WithdrawalRequest::create([
                    'id' => Str::uuid()->toString(),
                    'user_id' => $userId,
                    'withdrawal_number' => 'WTH-'.date('Ymd').'-'.strtoupper(Str::random(10)),
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
                    balance: (string) $lockedWallet->balance,
                    availableBalance: $finance->availableBalance($lockedWallet)->toString(),
                    version: (int) $lockedWallet->version,
                    reason: 'WITHDRAWAL_REQUEST'
                );

                return $withdrawal;
            });
        });
    }

    public function disburseWithdrawal(string $withdrawalId, string $operatorId): WithdrawalRequest
    {
        $finance = $this->canonicalWallets();

        return DB::transaction(function () use ($withdrawalId, $operatorId, $finance) {
            /** @var WithdrawalRequest $withdrawal */
            $withdrawal = WithdrawalRequest::where('id', $withdrawalId)->lockForUpdate()->firstOrFail();

            if (! in_array($withdrawal->status, ['PENDING_APPROVAL', 'APPROVED'], true)) {
                throw new RuntimeException("Withdrawal [{$withdrawal->id}] is in invalid status: {$withdrawal->status}");
            }

            $userId = (string) $withdrawal->user_id;
            $amount = (string) $withdrawal->amount;
            $currency = (string) $withdrawal->currency;
            $currencyEnum = Currency::from(strtoupper($currency));
            $moneyAmount = Money::of(bcadd($amount, '0', 2), $currencyEnum);

            /** @var Wallet $wallet */
            $wallet = Wallet::where('user_id', $userId)
                ->whereIn('type', ['main', 'primary'])
                ->where('currency', $currency)
                ->lockForUpdate()
                ->firstOrFail();

            $unlocked = $finance->unlockFunds($wallet, $moneyAmount);
            $finance->applyDebit($unlocked, $moneyAmount, FinancialTransactionType::Withdrawal);
            $wallet->refresh();

            $userLiabilityAccount = $this->ledgerService->getOrCreateUserAccount($userId, $currency);
            $bankAssetAccount = $this->ledgerService->getSystemAccount(
                accountNumber: "SYS-ASSET-BANK-{$withdrawal->payout_channel}-{$currency}",
                name: "Bank Disbursement Asset [{$withdrawal->payout_channel}]",
                type: 'ASSET',
                currency: $currency
            );

            $ledgerTx = $this->ledgerService->postTransaction(
                referenceType: 'WITHDRAWAL_DISBURSEMENT',
                referenceId: (string) $withdrawal->id,
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
                balance: (string) $wallet->balance,
                availableBalance: $finance->availableBalance($wallet)->toString(),
                version: (int) $wallet->version,
                reason: 'WITHDRAWAL_COMPLETED'
            );

            return $withdrawal;
        });
    }

    public function rejectWithdrawal(string $withdrawalId, string $operatorId, string $reason): WithdrawalRequest
    {
        $finance = $this->canonicalWallets();

        return DB::transaction(function () use ($withdrawalId, $operatorId, $reason, $finance) {
            /** @var WithdrawalRequest $withdrawal */
            $withdrawal = WithdrawalRequest::where('id', $withdrawalId)->lockForUpdate()->firstOrFail();

            if ($withdrawal->status !== 'PENDING_APPROVAL') {
                throw new RuntimeException("Cannot reject withdrawal in status: {$withdrawal->status}");
            }

            $currency = (string) $withdrawal->currency;
            $currencyEnum = Currency::from(strtoupper($currency));
            $moneyAmount = Money::of(bcadd((string) $withdrawal->amount, '0', 2), $currencyEnum);

            /** @var Wallet $wallet */
            $wallet = Wallet::where('user_id', $withdrawal->user_id)
                ->whereIn('type', ['main', 'primary'])
                ->where('currency', $currency)
                ->lockForUpdate()
                ->firstOrFail();

            $unlocked = $finance->unlockFunds($wallet, $moneyAmount);

            $withdrawal->update([
                'status' => 'REJECTED',
                'approved_by_operator_id' => $operatorId,
                'rejection_reason' => $reason,
            ]);

            $this->realtimeSyncService->syncWalletBalance(
                userId: (string) $withdrawal->user_id,
                walletType: 'main',
                currency: $currency,
                balance: (string) $unlocked->balance,
                availableBalance: $finance->availableBalance($unlocked)->toString(),
                version: (int) $unlocked->version,
                reason: 'WITHDRAWAL_REJECTED'
            );

            return $withdrawal;
        });
    }

    private function canonicalWallets(): CanonicalWalletService
    {
        return $this->walletService ?? app(CanonicalWalletService::class);
    }
}
