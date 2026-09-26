<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\Currency;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use Illuminate\Support\Carbon;

/**
 * Deterministic accounting/reconciliation export (CSV and JSON).
 *
 * READ-ONLY: this service never mutates a balance, ledger entry, or payout.
 * Rows are ordered by (created_at, id) so two exports of the same window are
 * byte-identical. Secrets (passwords, tokens, payout_details ciphertext, raw
 * national IDs) are never selected.
 */
final class FinancialReconciliationExportService
{
    /** Hard row ceiling so an export can never stream an unbounded table. */
    public const MAX_ROWS = 50000;

    public function __construct(
        private readonly FinancialReconciliationService $reconciliation,
    ) {
    }

    /**
     * Deterministic ledger + financial-transaction export window.
     *
     * @return array{
     *     generated_at: string,
     *     from: string|null,
     *     to: string|null,
     *     currency: string|null,
     *     row_count: int,
     *     truncated: bool,
     *     transactions: list<array<string, string|null>>,
     *     ledger_entries: list<array<string, string|null>>,
     *     reconciliation: array<string, mixed>|null,
     * }
     */
    public function export(
        ?Carbon $from = null,
        ?Carbon $to = null,
        ?Currency $currency = null,
        int $limit = 5000,
    ): array {
        $limit = max(1, min($limit, self::MAX_ROWS));

        $transactions = $this->transactionRows($from, $to, $currency, $limit);
        $ledgerRows = $this->ledgerRows($from, $to, $currency, $limit);

        return [
            'generated_at' => now()->toIso8601String(),
            'from' => $from?->toIso8601String(),
            'to' => $to?->toIso8601String(),
            'currency' => $currency?->value,
            'row_count' => count($transactions) + count($ledgerRows),
            'truncated' => count($transactions) >= $limit || count($ledgerRows) >= $limit,
            'transactions' => $transactions,
            'ledger_entries' => $ledgerRows,
            'reconciliation' => null,
        ];
    }

    /**
     * Full export including a reconciliation snapshot (still read-only).
     *
     * @return array<string, mixed>
     */
    public function exportWithReconciliation(
        ?Carbon $from = null,
        ?Carbon $to = null,
        ?Currency $currency = null,
        int $limit = 5000,
        string $initiatedBy = 'CLI:export',
    ): array {
        $payload = $this->export($from, $to, $currency, $limit);
        $report = $this->reconciliation->reconcile(
            from: $from,
            to: $to,
            currency: $currency,
            initiatedBy: $initiatedBy,
        );
        $payload['reconciliation'] = [
            'execution_id' => $report->executionId,
            'status' => $report->status->value,
            'ledger_difference' => $report->ledgerDifference,
            'anomaly_count' => $report->anomalyCount,
            'critical_anomaly_count' => $report->criticalAnomalyCount,
        ];

        return $payload;
    }

    /**
     * Deterministic CSV of financial_transactions (no PII beyond ids/refs).
     */
    public function toCsv(array $payload): string
    {
        $lines = [];
        $lines[] = 'transaction_id,reference_number,account_reference,type,debit,credit,currency,status,created_at,correlation_reference';

        foreach ($payload['transactions'] as $row) {
            $lines[] = implode(',', array_map(
                static fn ($v): string => self::csvCell($v),
                [
                    $row['transaction_id'],
                    $row['reference_number'],
                    $row['account_reference'],
                    $row['type'],
                    $row['debit'],
                    $row['credit'],
                    $row['currency'],
                    $row['status'],
                    $row['created_at'],
                    $row['correlation_reference'],
                ],
            ));
        }

        foreach ($payload['ledger_entries'] as $row) {
            $lines[] = implode(',', array_map(
                static fn ($v): string => self::csvCell($v),
                [
                    'LE-'.$row['ledger_entry_id'],
                    $row['entry_reference'],
                    $row['account_reference'],
                    $row['type'],
                    $row['debit'],
                    $row['credit'],
                    $row['currency'],
                    $row['status'],
                    $row['created_at'],
                    $row['correlation_reference'],
                ],
            ));
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @return list<array<string, string|null>>
     */
    private function transactionRows(?Carbon $from, ?Carbon $to, ?Currency $currency, int $limit): array
    {
        $query = FinancialTransaction::query()
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit);

        if ($from !== null) {
            $query->where('created_at', '>=', $from);
        }
        if ($to !== null) {
            $query->where('created_at', '<=', $to);
        }
        if ($currency !== null) {
            $query->where('currency', $currency->value);
        }

        return $query->get()
            ->map(static fn (FinancialTransaction $t): array => [
                'transaction_id' => (string) $t->getKey(),
                'reference_number' => (string) $t->reference_number,
                'account_reference' => $t->wallet_id !== null ? 'wallet:'.$t->wallet_id : ($t->user_id !== null ? 'user:'.$t->user_id : null),
                'type' => $t->type instanceof \BackedEnum ? $t->type->value : (string) $t->type,
                'debit' => self::signedSide($t, 'debit'),
                'credit' => self::signedSide($t, 'credit'),
                'currency' => $t->currency instanceof \BackedEnum ? $t->currency->value : (string) $t->currency,
                'status' => $t->status instanceof \BackedEnum ? $t->status->value : (string) $t->status,
                'created_at' => $t->created_at?->toIso8601String(),
                'correlation_reference' => is_array($t->metadata) ? ($t->metadata['correlation_id'] ?? $t->idempotency_key ?? null) : $t->idempotency_key,
            ])
            ->all();
    }

    /**
     * @return list<array<string, string|null>>
     */
    private function ledgerRows(?Carbon $from, ?Carbon $to, ?Currency $currency, int $limit): array
    {
        $query = LedgerEntry::query()
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit);

        if ($from !== null) {
            $query->where('created_at', '>=', $from);
        }
        if ($to !== null) {
            $query->where('created_at', '<=', $to);
        }
        if ($currency !== null) {
            $query->where('currency', $currency->value);
        }

        return $query->get()
            ->map(static function (LedgerEntry $e): array {
                $type = $e->type instanceof \BackedEnum ? $e->type->value : (string) $e->type;
                $amount = (string) $e->amount;
                $isDebit = $type === 'debit';

                return [
                    'ledger_entry_id' => (string) $e->getKey(),
                    'entry_reference' => 'LE-'.$e->getKey(),
                    'account_reference' => $e->ledger_account_id !== null ? 'account:'.$e->ledger_account_id : null,
                    'type' => $type,
                    'debit' => $isDebit ? $amount : '0.00',
                    'credit' => $isDebit ? '0.00' : $amount,
                    'currency' => $e->currency instanceof \BackedEnum ? $e->currency->value : (string) $e->currency,
                    'status' => 'posted',
                    'created_at' => ($e->posted_at ?? $e->created_at)?->toIso8601String(),
                    'correlation_reference' => is_array($e->metadata) ? ($e->metadata['correlation_id'] ?? null) : null,
                ];
            })
            ->all();
    }

    /**
     * Money-side classification without mutating the row: outgoing types are
     * debits from the player-wallet view, incoming are credits. Exact strings only.
     */
    private static function signedSide(FinancialTransaction $t, string $side): string
    {
        $type = $t->type instanceof \BackedEnum ? $t->type->value : (string) $t->type;
        $amount = (string) $t->amount;

        $debitTypes = ['withdrawal', 'bet_placement', 'payout', 'commission', 'fee', 'debit'];
        $creditTypes = ['deposit', 'bet_refund', 'reversal', 'credit'];

        if ($side === 'debit' && in_array($type, $debitTypes, true)) {
            return $amount;
        }
        if ($side === 'credit' && in_array($type, $creditTypes, true)) {
            return $amount;
        }

        // Settlement/adjustment/transfer: expose as credit when positive amount
        // with type settlement (pool funding), else zero on both for clarity.
        if ($side === 'credit' && $type === 'settlement') {
            return $amount;
        }

        return '0.00';
    }

    private static function csvCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        $s = (string) $value;
        if (str_contains($s, ',') || str_contains($s, '"') || str_contains($s, "\n")) {
            return '"'.str_replace('"', '""', $s).'"';
        }

        return $s;
    }
}
