<?php

declare(strict_types=1);

namespace App\Services\Wallet;

use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\WalletStatus;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Wallet;
use App\Services\Broadcast\RealtimeSyncService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService as CanonicalWalletService;
use App\Services\Ledger\DoubleEntryLedgerService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Class WalletMutationService
 *
 * Enforces transaction-bound, version-incremented, pessimistic-locked wallet operations
 * delegating balance mutations exclusively through the canonical finance WalletService.
 */
class WalletMutationService
{
    private readonly RealtimeSyncService $realtimeSyncService;

    public function __construct(
        private readonly DoubleEntryLedgerService $ledgerService,
        mixed $realtimeSyncService = null,
        private readonly ?CanonicalWalletService $walletService = null,
    ) {
        $this->realtimeSyncService = $realtimeSyncService instanceof RealtimeSyncService
            ? $realtimeSyncService
            : app(RealtimeSyncService::class);
    }

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

        $this->assertInsideApplicationTransaction();

        $candidateTypes = $walletType === 'main' ? ['main', 'primary'] : [$walletType];

        /** @var Wallet $wallet */
        $wallet = Wallet::where('user_id', $userId)
            ->whereIn('type', $candidateTypes)
            ->where('currency', $currency)
            ->lockForUpdate()
            ->firstOrFail();

        if ($wallet->status !== WalletStatus::Active) {
            throw new RuntimeException("Wallet [{$wallet->id}] is not active (status: {$wallet->status->value}).");
        }

        $finance = $this->canonicalWallets();
        $currencyEnum = Currency::from(strtoupper($currency));
        // Validate the original amount, not a truncated copy: the wallet and
        // legacy ledger must never disagree over fractional currency units.
        $money = Money::of($amount, $currencyEnum);

        try {
            $finance->applyDebit(
                $wallet,
                $money,
                FinancialTransactionType::Transfer,
            );
        } catch (InsufficientBalanceException) {
            $available = $finance->availableBalance($wallet)->toString();
            throw new RuntimeException(
                "Insufficient funds for user [{$userId}]. Available: {$available} {$currency}, Required: {$amount} {$currency}."
            );
        }

        $wallet->refresh();

        $userLiabilityAccount = $this->ledgerService->getOrCreateUserAccount($userId, $currency);
        $systemRevenueAccount = $this->ledgerService->getSystemAccount(
            accountNumber: "SYS-REV-{$currency}",
            name: "System Pool / Revenue Account [{$currency}]",
            type: 'REVENUE',
            currency: $currency
        );

        $this->ledgerService->postTransaction(
            referenceType: $referenceType,
            referenceId: $referenceId,
            currency: $currency,
            entries: [
                [
                    'account_id' => $userLiabilityAccount->id,
                    'wallet_id' => (int) $wallet->id,
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

        $this->realtimeSyncService->syncWalletBalance(
            userId: $userId,
            walletType: $walletType,
            currency: $currency,
            balance: (string) $wallet->balance,
            availableBalance: $finance->availableBalance($wallet)->toString(),
            version: (int) $wallet->version,
            reason: $referenceType
        );

        return $wallet;
    }

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

        $this->assertInsideApplicationTransaction();

        $candidateTypes = $walletType === 'main' ? ['main', 'primary'] : [$walletType];

        /** @var Wallet $wallet */
        $wallet = Wallet::where('user_id', $userId)
            ->whereIn('type', $candidateTypes)
            ->where('currency', $currency)
            ->lockForUpdate()
            ->firstOrFail();

        if ($wallet->status !== WalletStatus::Active) {
            throw new RuntimeException("Wallet [{$wallet->id}] is not active (status: {$wallet->status->value}).");
        }

        $finance = $this->canonicalWallets();
        $currencyEnum = Currency::from(strtoupper($currency));
        // Preserve the canonical maximum-balance guard and reject precision
        // that would otherwise be truncated before the ledger is posted.
        $finance->applyCredit(
            $wallet,
            Money::of($amount, $currencyEnum),
            FinancialTransactionType::Transfer,
        );

        $wallet->refresh();

        $userLiabilityAccount = $this->ledgerService->getOrCreateUserAccount($userId, $currency);
        $systemPayoutAccount = $this->ledgerService->getSystemAccount(
            accountNumber: "SYS-EXP-PAYOUT-{$currency}",
            name: "System Prize Payout Expense Account [{$currency}]",
            type: 'EXPENSE',
            currency: $currency
        );

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
                    'wallet_id' => (int) $wallet->id,
                    'direction' => 'CREDIT',
                    'amount' => $amount,
                ],
            ],
            idempotencyKey: $idempotencyKey,
            description: $description ?? "Wallet credit for {$referenceType} #{$referenceId}"
        );

        $this->realtimeSyncService->syncWalletBalance(
            userId: $userId,
            walletType: $walletType,
            currency: $currency,
            balance: (string) $wallet->balance,
            availableBalance: $finance->availableBalance($wallet)->toString(),
            version: (int) $wallet->version,
            reason: $referenceType
        );

        return $wallet;
    }

    private function canonicalWallets(): CanonicalWalletService
    {
        return $this->walletService ?? app(CanonicalWalletService::class);
    }

    private function assertInsideApplicationTransaction(): void
    {
        $level = DB::transactionLevel();
        $requiredLevel = app()->runningUnitTests() ? 2 : 1;

        if ($level < $requiredLevel) {
            throw new RuntimeException('Wallet operations must run inside an active database transaction.');
        }
    }
}
