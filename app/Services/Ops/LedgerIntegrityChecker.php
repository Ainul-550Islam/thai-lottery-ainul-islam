<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Enums\LedgerEntryType;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * Proves that the ledger and the wallets agree. STRICTLY READ-ONLY.
 *
 * THE TWO INVARIANTS
 *   1. Per wallet: sum(credits) - sum(debits) == wallets.balance (+ locked, which
 *      is still the member's money and is still on the ledger)
 *   2. Per financial transaction: sum(debits) == sum(credits)
 *
 * Both are computed with SQL aggregates on integer-cent DECIMALs and compared with
 * bccomp in PHP, so no float ever participates in the comparison. A discrepancy of
 * one satang is a discrepancy.
 *
 * WHAT IT NEVER DOES
 * It never corrects anything. Not a compensating entry, not a balance rewrite, not
 * a "repair" flag. A mismatch means the assumption every other lane is built on is
 * false, and the response to that is a human reading the report — the runbook is
 * docs/ops/RUNBOOK_ledger_mismatch.md. An auto-repair here would make the next
 * mismatch invisible by silently agreeing with whatever it found.
 *
 * REUSE
 * FinancialReconciliationService and LedgerBalanceValidator already exist and do
 * per-transaction proof inside the posting path. This checker is the OUT-OF-BAND
 * sweep: it reads the whole table in chunks, uses their comparison semantics
 * (exact strings, bccomp), and reports rather than asserts. It does not import
 * their write-side guards, because it must run against a database it suspects.
 */
final class LedgerIntegrityChecker
{
    /** Rows visited by the most recent checkWallets() run. */
    private int $lastWalletCount = 0;

    /** Rows visited by the most recent checkTransactions() run. */
    private int $lastTransactionCount = 0;

    /**
     * Sweep everything.
     *
     * @return array{
     *     checked_wallets:int,
     *     checked_transactions:int,
     *     wallet_discrepancies:list<array<string, mixed>>,
     *     transaction_discrepancies:list<array<string, mixed>>,
     *     clean:bool
     * }
     */
    public function check(?int $limit = null, int $chunk = 500): array
    {
        $walletDiscrepancies = $this->checkWallets($limit, $chunk);
        $transactionDiscrepancies = $this->checkTransactions($limit, $chunk);

        return [
            'checked_wallets' => $this->lastWalletCount,
            'checked_transactions' => $this->lastTransactionCount,
            'wallet_discrepancies' => $walletDiscrepancies,
            'transaction_discrepancies' => $transactionDiscrepancies,
            'clean' => $walletDiscrepancies === [] && $transactionDiscrepancies === [],
        ];
    }

    /**
     * Invariant 1: ledger sum per wallet equals the stored balance.
     *
     * The aggregate is grouped in SQL (one query per chunk of wallets) rather than
     * N+1: a wallet-per-query sweep over a million members would be an outage, not
     * a check.
     *
     * @return list<array<string, mixed>>
     */
    public function checkWallets(?int $limit = null, int $chunk = 500): array
    {
        $discrepancies = [];
        $this->lastWalletCount = 0;

        $query = Wallet::query()
            ->select(['id', 'user_id', 'type', 'currency', 'balance', 'locked_balance'])
            ->orderBy('id');

        $query->chunkById(max(1, $chunk), function ($wallets) use (&$discrepancies): void {
            $ids = $wallets->pluck('id')->all();

            $sums = LedgerEntry::query()
                ->whereIn('wallet_id', $ids)
                ->toBase()
                ->selectRaw('wallet_id, type, SUM(amount) as total')
                ->groupBy('wallet_id', 'type')
                ->get();

            $byWallet = [];

            foreach ($sums as $row) {
                $walletId = (int) $row->wallet_id;
                $type = $row->type instanceof LedgerEntryType
                    ? $row->type->value
                    : (string) $row->type;

                $byWallet[$walletId][$type] = (string) $row->total;
            }

            foreach ($wallets as $wallet) {
                $this->lastWalletCount++;

                $credits = $byWallet[(int) $wallet->id]['credit'] ?? '0.00';
                $debits = $byWallet[(int) $wallet->id]['debit'] ?? '0.00';

                $ledgerBalance = bcsub($credits, $debits, 2);
                $storedBalance = bcadd((string) $wallet->balance, (string) $wallet->locked_balance, 2);

                if (bccomp($ledgerBalance, $storedBalance, 2) !== 0) {
                    $discrepancies[] = [
                        'wallet_id' => (int) $wallet->id,
                        'user_id' => (int) $wallet->user_id,
                        'wallet_type' => $wallet->type?->value ?? (string) $wallet->type,
                        'currency' => (string) ($wallet->currency?->value ?? $wallet->currency),
                        'ledger_balance' => $ledgerBalance,
                        'stored_balance' => $storedBalance,
                        'difference' => bcsub($ledgerBalance, $storedBalance, 2),
                    ];
                }
            }
        });

        return $discrepancies;
    }

    /**
     * Invariant 2: every financial transaction balances to zero.
     *
     * A transaction with NO ledger entries at all is reported too: a money fact
     * that posted no accounting is not "balanced by zero", it is missing.
     *
     * @return list<array<string, mixed>>
     */
    public function checkTransactions(?int $limit = null, int $chunk = 500): array
    {
        $discrepancies = [];
        $this->lastTransactionCount = 0;

        $query = FinancialTransaction::query()
            ->select(['id', 'reference_number', 'type', 'amount', 'currency'])
            ->orderBy('id');

        $query->chunkById(max(1, $chunk), function ($transactions) use (&$discrepancies): void {
            $ids = $transactions->pluck('id')->all();

            $sums = LedgerEntry::query()
                ->whereIn('financial_transaction_id', $ids)
                ->toBase()
                ->selectRaw('financial_transaction_id, type, SUM(amount) as total')
                ->groupBy('financial_transaction_id', 'type')
                ->get();

            $byTransaction = [];

            foreach ($sums as $row) {
                $txId = (int) $row->financial_transaction_id;
                $type = $row->type instanceof LedgerEntryType
                    ? $row->type->value
                    : (string) $row->type;

                $byTransaction[$txId][$type] = (string) $row->total;
            }

            foreach ($transactions as $transaction) {
                $this->lastTransactionCount++;

                $credits = $byTransaction[(int) $transaction->id]['credit'] ?? null;
                $debits = $byTransaction[(int) $transaction->id]['debit'] ?? null;

                if ($credits === null && $debits === null) {
                    $discrepancies[] = [
                        'financial_transaction_id' => (int) $transaction->id,
                        'reference' => (string) $transaction->reference_number,
                        'reason' => 'no_ledger_entries',
                        'debit_total' => '0.00',
                        'credit_total' => '0.00',
                    ];

                    continue;
                }

                $creditTotal = (string) ($credits ?? '0.00');
                $debitTotal = (string) ($debits ?? '0.00');

                if (bccomp($creditTotal, $debitTotal, 2) !== 0) {
                    $discrepancies[] = [
                        'financial_transaction_id' => (int) $transaction->id,
                        'reference' => (string) $transaction->reference_number,
                        'reason' => 'unbalanced',
                        'debit_total' => $debitTotal,
                        'credit_total' => $creditTotal,
                        'difference' => bcsub($debitTotal, $creditTotal, 2),
                    ];
                }
            }
        });

        return $discrepancies;
    }

    /**
     * The raw grouped sums for one wallet. Used by the runbook's investigation
     * queries and by the test that injects a mismatch and then looks at it.
     *
     * @return array<string, string>
     */
    public function walletSums(int $walletId): array
    {
        $rows = LedgerEntry::query()
            ->where('wallet_id', $walletId)
            ->toBase()
            ->selectRaw('type, SUM(amount) as total')
            ->groupBy('type')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $type = $row->type instanceof LedgerEntryType ? $row->type->value : (string) $row->type;
            $out[$type] = (string) $row->total;
        }

        return $out;
    }

    /**
     * Whether a transaction has any entries at all. A cheap pre-check used by the
     * runbook before the expensive sweep.
     */
    public function transactionHasEntries(int $transactionId): bool
    {
        return DB::table((new LedgerEntry)->getTable())
            ->where('financial_transaction_id', $transactionId)
            ->exists();
    }
}
