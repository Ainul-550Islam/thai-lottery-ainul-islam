<?php

declare(strict_types=1);

namespace App\Services\Ledger;

use App\Enums\Currency;
use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Class LedgerReconciliationService
 *
 * Internal ledger arithmetic, selected wallet comparisons and legacy hash-chain checks.
 * It does not prove completeness of all financial obligations or external bank evidence.
 */
class LedgerReconciliationService
{
    /**
     * Run scoped internal checks. Single-currency legacy totals are null when
     * more than one currency is present; currencies are never added together.
     *
     * @return array<string, mixed>
     */
    public function auditSystemIntegrity(): array
    {
        // Opposite variances in THB and USD must never cancel into a green
        // global total. Check every entry's account and transaction identity as
        // well as the per-currency debit/credit arithmetic.
        $allEntries = DB::table('ledger_entries as e')
            ->leftJoin('ledger_accounts as a', 'a.id', '=', 'e.ledger_account_id')
            ->leftJoin('financial_transactions as f', 'f.id', '=', 'e.financial_transaction_id')
            ->leftJoin('ledger_transactions as j', 'j.id', '=', 'e.ledger_transaction_id')
            ->whereNull('e.deleted_at')
            ->select(['e.id', 'e.type', 'e.direction', 'e.currency', 'e.ledger_account_id', 'e.ledger_transaction_id', 'e.financial_transaction_id',
                'a.currency as account_currency', 'f.currency as financial_currency', 'j.currency as journal_currency'])
            ->selectRaw('CAST(e.amount AS CHAR) AS exact_amount')
            ->get();

        $byCurrency = [];
        $byTransaction = [];
        $currencyIssues = [];
        foreach ($allEntries as $row) {
            $code = Currency::tryFromCode(is_string($row->currency) ? $row->currency : null)?->value;
            $side = strtoupper((string) ($row->direction ?: $row->type));
            $entryType = strtoupper((string) $row->type);
            if ($code === null || strtoupper((string) $row->account_currency) !== $code
                || strtoupper((string) $row->financial_currency) !== $code
                || ($row->ledger_transaction_id !== null && strtoupper((string) $row->journal_currency) !== $code)) {
                $currencyIssues[] = ['entry_id' => (int) $row->id, 'issue' => 'currency_identity_mismatch'];
            }
            if (! in_array($side, ['DEBIT', 'CREDIT'], true) || $entryType !== $side) {
                $currencyIssues[] = ['entry_id' => (int) $row->id, 'issue' => 'entry_direction_invalid'];
                continue;
            }
            $amount = $row->exact_amount;
            if (! is_string($amount) || preg_match('/^\d+(?:\.\d{1,4})?$/D', $amount) !== 1) {
                $currencyIssues[] = ['entry_id' => (int) $row->id, 'issue' => 'entry_amount_invalid'];
                continue;
            }
            if ($code === null) {
                continue;
            }
            $byCurrency[$code] ??= ['debits' => '0.0000', 'credits' => '0.0000', 'delta' => '0.0000'];
            $key = $side === 'DEBIT' ? 'debits' : 'credits';
            $byCurrency[$code][$key] = bcadd($byCurrency[$code][$key], $amount, 4);
            $transactionId = (int) $row->financial_transaction_id;
            $byTransaction[$transactionId][$code] ??= ['debits' => '0.0000', 'credits' => '0.0000'];
            $byTransaction[$transactionId][$code][$key] = bcadd($byTransaction[$transactionId][$code][$key], $amount, 4);
        }
        $transactionIssues = [];
        foreach ($byTransaction as $transactionId => $currencies) {
            foreach ($currencies as $code => $totals) {
                $delta = bcsub($totals['debits'], $totals['credits'], 4);
                if (bccomp($delta, '0', 4) !== 0) {
                    $transactionIssues[] = ['financial_transaction_id' => $transactionId, 'currency' => $code, 'delta' => $delta];
                }
            }
        }
        ksort($byCurrency);
        foreach ($byCurrency as &$totals) {
            $totals['delta'] = bcsub($totals['debits'], $totals['credits'], 4);
        }
        unset($totals);
        $single = count($byCurrency) === 1 ? reset($byCurrency) : null;
        if ($byCurrency === []) {
            $single = ['debits' => '0.0000', 'credits' => '0.0000', 'delta' => '0.0000'];
        }

        // 2. Reconcile Wallets against User Liability Ledger Accounts
        $userAccounts = LedgerAccount::query()
            ->where('type', 'liability')
            ->whereNotNull('metadata')
            ->get()
            ->filter(static fn (LedgerAccount $account): bool => isset($account->metadata['owner_user_id']) && $account->metadata['owner_user_id'] !== '')
            ->values();
        $walletDiscrepancies = [];
        $unmatchedUserAccounts = [];
        $reconciledAccounts = 0;

        foreach ($userAccounts as $account) {
            $ownerUserId = (string) $account->metadata['owner_user_id'];
            $currencyCode = $account->currency instanceof \BackedEnum
                ? (string) $account->currency->value
                : (string) $account->currency;

            $wallets = Wallet::where('user_id', $ownerUserId)
                ->where('currency', $currencyCode)
                ->get();

            if ($wallets->isEmpty()) {
                $unmatchedUserAccounts[] = [
                    'account_id' => (int) $account->id,
                    'user_id' => $ownerUserId,
                    'currency' => $currencyCode,
                ];
                continue;
            }

            $walletBalance = '0.0000';
            foreach ($wallets as $wallet) {
                $walletBalance = bcadd($walletBalance, (string) $wallet->balance, 4);
            }

            // Reuse the already fetched decimal strings; avoid SQL float SUM
            // and prevent driver float conversion in this comparison.
            $accountEntries = $allEntries->where('ledger_account_id', $account->id);
            $derivedBalance = '0.0000';
            foreach ($accountEntries as $entryRow) {
                $amount = $entryRow->exact_amount;
                if (! is_string($amount) || preg_match('/^\d+(?:\.\d{1,4})?$/D', $amount) !== 1) {
                    continue; // The input issue already makes the report CORRUPTED.
                }
                $side = strtoupper((string) ($entryRow->direction ?: $entryRow->type));
                if ($side === 'CREDIT') {
                    $derivedBalance = bcadd($derivedBalance, $amount, 4);
                } elseif ($side === 'DEBIT') {
                    $derivedBalance = bcsub($derivedBalance, $amount, 4);
                }
            }

            if (bccomp($walletBalance, $derivedBalance, 4) !== 0) {
                $walletDiscrepancies[] = [
                    'user_id' => $ownerUserId,
                    'currency' => $currencyCode,
                    'wallet_balance' => $walletBalance,
                    'ledger_derived_balance' => $derivedBalance,
                    'difference' => bcsub($walletBalance, $derivedBalance, 4),
                ];
            } else {
                $reconciledAccounts++;
            }
        }

        // 3. Verify Cryptographic SHA-256 Hash Chain Integrity
        $tamperedTransactions = $this->verifyCryptographicChain();

        $isHealthy = empty($currencyIssues)
            && empty($transactionIssues)
            && empty($unmatchedUserAccounts)
            && empty($walletDiscrepancies)
            && empty($tamperedTransactions);
        foreach ($byCurrency as $totals) {
            if (bccomp($totals['delta'], '0.0000', 4) !== 0) {
                $isHealthy = false;
            }
        }

        $report = [
            'status' => $isHealthy ? 'HEALTHY' : 'CORRUPTED',
            'scope' => 'internal_ledger_checks_only',
            'by_currency' => $byCurrency,
            'currency_identity_issues' => $currencyIssues,
            'transaction_balance_issues' => $transactionIssues,
            'total_debits' => $single['debits'] ?? null,
            'total_credits' => $single['credits'] ?? null,
            'ledger_balance_delta' => $single['delta'] ?? null,
            'reconciled_accounts_count' => $reconciledAccounts,
            'unmatched_user_accounts' => $unmatchedUserAccounts,
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
