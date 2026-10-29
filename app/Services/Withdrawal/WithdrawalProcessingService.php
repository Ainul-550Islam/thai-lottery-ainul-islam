<?php

declare(strict_types=1);

namespace App\Services\Withdrawal;

use App\Exceptions\FinancialException;
use App\Models\WithdrawalRequest;
use App\Services\Broadcast\RealtimeSyncService;
use App\Services\Finance\WalletService as CanonicalWalletService;
use App\Services\Ledger\DoubleEntryLedgerService;
use App\Services\Lock\DistributedLockService;
use App\Services\Wallet\WalletMutationService;

/**
 * Compatibility boundary for the obsolete withdrawal_requests workflow.
 *
 * Its rows do not identify an owned canonical hold or authenticated provider
 * payout evidence. The old path could therefore release another obligation's
 * aggregate hold, or debit a wallet and mark a payout complete without any bank
 * transfer. No existing row is converted, released, completed or discarded by
 * this boundary. An audited reconciliation/migration is required first.
 *
 * Current withdrawals remain owned by the canonical Finance and Payment
 * withdrawal services; this class must not become a second disbursement path.
 */
class WithdrawalProcessingService
{
    // Keep the previous constructor contract for callers while the legacy
    // boundary is inactive. None of these collaborators are invoked here.
    public function __construct(
        DistributedLockService $lockService,
        WalletMutationService $walletMutationService,
        DoubleEntryLedgerService $ledgerService,
        RealtimeSyncService $realtimeSyncService,
        ?CanonicalWalletService $walletService = null,
    ) {}

    public function requestWithdrawal(
        string $userId,
        string $amount,
        string $payoutChannel,
        string $accountName,
        string $accountNumber,
        ?string $bankCode = null,
        string $currency = 'THB',
    ): WithdrawalRequest {
        $this->refuse('request');
    }

    public function disburseWithdrawal(string $withdrawalId, string $operatorId): WithdrawalRequest
    {
        $this->refuse('disbursement');
    }

    public function rejectWithdrawal(string $withdrawalId, string $operatorId, string $reason): WithdrawalRequest
    {
        $this->refuse('rejection');
    }

    private function refuse(string $operation): never
    {
        throw FinancialException::withCode(
            'legacy_withdrawal_reconciliation_required',
            'Legacy withdrawal '.$operation.' is unavailable until its existing obligations and holds are reconciled. No funds or records were changed.',
            ['operation' => $operation],
        );
    }
}
