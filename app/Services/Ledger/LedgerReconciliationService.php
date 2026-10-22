<?php

declare(strict_types=1);

namespace App\Services\Ledger;

use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Class LedgerReconciliationService
 *
 * Provides enterprise-grade general ledger reconciliation, wallet-to-ledger balance verification,
 * and cryptographic SHA-256 blockchain chain tamper-detection.
 */
class LedgerReconciliationService
{
    /**
     * Run a comprehensive system-wide financial integrity audit.
     *
     * @return array{
     *     status: 'HEALTHY'|'CORRUPTED',
     *     total_debits: string,
     *     total_credits: string,
     *     ledger_balance_delta: string,
     *     reconciled_accounts_count: int,
     *     wallet_discrepancies: array<int, array{user_id: string, wallet_balance: string, ledger_derived_balance: string, difference: string}>,
     *     tampered_transactions: array<int, array{id: string, transaction_number: string, expected_hash: string, actual_hash: string}>
     * }
     */
    public function auditSystemIntegrity(): array
    {
        // 1. Verify Global Ledger Invariant: Total Debits == Total Credits
        $totals = DB::table('ledger_entries')
            ->selectRaw("
                SUM(CASE WHEN direction = 'DEBIT' THEN amount ELSE 0 END) as total_debits,
                SUM(CASE WHEN direction = 'CREDIT' THEN amount ELSE 0 END) as total_credits
            ")
            ->first();

        $totalDebits = number_format((float) ($totals->total_debits ?? 0), 4, '.', '');
        $totalCredits = number_format((float) ($totals->total_credits ?? 0), 4, '.', '');
        $ledgerDelta = bcsub($totalDebits, $totalCredits, 4);

        // 2. Reconcile Wallets against User Liability Ledger Accounts
        $userAccounts = LedgerAccount::query()
            ->where('type', 'liability')
            ->whereNotNull('metadata')
            ->get()
            ->filter(static fn (LedgerAccount $account): bool => isset($account->metadata['owner_user_id']) && $account->metadata['owner_user_id'] !== '')
            ->values();
        $walletDiscrepancies = [];

        foreach ($userAccounts as $account) {
            $ownerUserId = (string) $account->metadata['owner_user_id'];
            $currencyCode = $account->currency instanceof \BackedEnum
                ? (string) $account->currency->value
                : (string) $account->currency;

            $wallets = Wallet::where('user_id', $ownerUserId)
                ->where('currency', $currencyCode)
                ->get();

            if ($wallets->isEmpty()) {
                continue;
            }

            $walletBalance = '0.0000';
            foreach ($wallets as $wallet) {
                $walletBalance = bcadd($walletBalance, (string) $wallet->balance, 4);
            }

            // Derive actual balance from all historical ledger entries for this account
            $entrySums = DB::table('ledger_entries')
                ->where('ledger_account_id', $account->id)
                ->selectRaw("
                    SUM(CASE WHEN direction = 'CREDIT' THEN amount ELSE 0 END) -
                    SUM(CASE WHEN direction = 'DEBIT' THEN amount ELSE 0 END) as derived_balance
                ")
                ->value('derived_balance');

            $derivedBalance = number_format((float) ($entrySums ?? 0), 4, '.', '');

            if (bccomp($walletBalance, $derivedBalance, 4) !== 0) {
                $walletDiscrepancies[] = [
                    'user_id' => $ownerUserId,
                    'wallet_balance' => $walletBalance,
                    'ledger_derived_balance' => $derivedBalance,
                    'difference' => bcsub($walletBalance, $derivedBalance, 4),
                ];
            }
        }

        // 3. Verify Cryptographic SHA-256 Hash Chain Integrity
        $tamperedTransactions = $this->verifyCryptographicChain();

        $isHealthy = bccomp($ledgerDelta, '0.0000', 4) === 0
            && empty($walletDiscrepancies)
            && empty($tamperedTransactions);

        $report = [
            'status' => $isHealthy ? 'HEALTHY' : 'CORRUPTED',
            'total_debits' => $totalDebits,
            'total_credits' => $totalCredits,
            'ledger_balance_delta' => $ledgerDelta,
            'reconciled_accounts_count' => $userAccounts->count(),
            'wallet_discrepancies' => $walletDiscrepancies,
            'tampered_transactions' => $tamperedTransactions,
        ];

        if (! $isHealthy) {
            Log::critical('FINANCIAL INTEGRITY RECONCILIATION FAILED!', $report);
        }

        return $report;
    }

    /**
     * Recompute and verify every transaction's hash against its predecessor in the chain.
     */
    private function verifyCryptographicChain(): array
    {
        $transactions = LedgerTransaction::query()->get();
        if ($transactions->isEmpty()) {
            return [];
        }

        $tampered = [];
        $byPreviousHash = [];
        foreach ($transactions as $tx) {
            $prevKey = $tx->previous_hash ?? str_repeat('0', 64);
            $byPreviousHash[$prevKey][] = $tx;
        }

        // Walk the singly-linked cryptographic hash chain starting from the genesis link.
        $ordered = [];
        $visitedIds = [];
        $expectedPreviousHash = str_repeat('0', 64);

        while (! empty($byPreviousHash[$expectedPreviousHash])) {
            $nextTx = array_shift($byPreviousHash[$expectedPreviousHash]);
            $ordered[] = $nextTx;
            $visitedIds[$nextTx->id] = true;
            $expectedPreviousHash = $nextTx->hash;
        }

        // Any transaction not reachable on the unbroken chain from genesis has a broken pointer.
        foreach ($transactions as $tx) {
            if (! isset($visitedIds[$tx->id])) {
                $ordered[] = $tx;
            }
        }

        $expectedPreviousHash = str_repeat('0', 64);

        foreach ($ordered as $tx) {
            // Verify previous hash pointer
            if ($tx->previous_hash !== $expectedPreviousHash) {
                $tampered[] = [
                    'id' => $tx->id,
                    'transaction_number' => $tx->transaction_number,
                    'issue' => 'BROKEN_PREVIOUS_HASH_POINTER',
                    'expected_prev' => $expectedPreviousHash,
                    'actual_prev' => $tx->previous_hash,
                ];
            }

            // Recompute expected hash
            $rawPayload = implode('|', [
                $tx->previous_hash ?? str_repeat('0', 64),
                $tx->transaction_number,
                $tx->reference_type,
                $tx->reference_id,
                $tx->currency,
                $tx->total_amount,
                $tx->idempotency_key ?? '',
            ]);
            $recomputedHash = hash('sha256', $rawPayload);

            if (! hash_equals($recomputedHash, $tx->hash)) {
                $tampered[] = [
                    'id' => $tx->id,
                    'transaction_number' => $tx->transaction_number,
                    'issue' => 'TAMPERED_TRANSACTION_PAYLOAD',
                    'expected_hash' => $recomputedHash,
                    'actual_hash' => $tx->hash,
                ];
            }

            $expectedPreviousHash = $tx->hash;
        }

        return $tampered;
    }
}
