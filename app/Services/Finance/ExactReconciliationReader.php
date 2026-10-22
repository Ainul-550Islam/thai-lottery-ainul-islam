<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Exceptions\FinancialException;
use Illuminate\Database\Query\Builder;

/** Exact row-wise audit arithmetic. Never uses floating SQL SUM or length-limited concatenation. */
final class ExactReconciliationReader
{
    public function sum(Builder $query, string $column): string
    {
        if (! in_array($column, ['amount', 'stake_amount', 'commission_amount'], true)) {
            throw new \LogicException('Only server-owned accounting columns may be summed.');
        }
        $total = '0.00';
        foreach ((clone $query)->selectRaw('CAST('.$column.' AS CHAR) AS amount')->orderBy('id')->cursor() as $row) {
            $total = bcadd($total, $this->decimal($row->amount), 2);
        }

        return $total;
    }

    public function sides(Builder $query): array
    {
        $totals = ['debit' => '0.00', 'credit' => '0.00'];
        foreach ((clone $query)->select('type')->selectRaw('CAST(amount AS CHAR) AS amount')->orderBy('id')->cursor() as $row) {
            $this->add($totals, $row);
        }

        return $totals;
    }

    /** The supplied query aliases ledger_entries as le. One accumulator, not a table-sized concatenation. */
    public function transactions(Builder $query): \Generator
    {
        $current = null;
        $totals = ['debit' => '0.00', 'credit' => '0.00'];
        $count = 0;
        $rows = (clone $query)->select(['le.financial_transaction_id as tx_id', 'le.type'])->selectRaw('CAST(le.amount AS CHAR) AS amount')
            ->orderBy('le.financial_transaction_id')->orderBy('le.id')->cursor();
        foreach ($rows as $row) {
            $id = (int) $row->tx_id;
            if ($current !== null && $id !== $current) {
                yield (object) ['tx_id' => $current, 'debits' => $totals['debit'], 'credits' => $totals['credit'], 'entry_count' => $count];
                $totals = ['debit' => '0.00', 'credit' => '0.00'];
                $count = 0;
            }
            $current = $id;
            $this->add($totals, $row);
            $count++;
        }
        if ($current !== null) {
            yield (object) ['tx_id' => $current, 'debits' => $totals['debit'], 'credits' => $totals['credit'], 'entry_count' => $count];
        }
    }

    private function add(array &$totals, object $row): void
    {
        if (! array_key_exists($row->type, $totals)) {
            throw FinancialException::withCode('reconciliation_invalid_entry', 'Ledger side is indeterminate; no passing report can be issued.');
        }
        $amount = $this->decimal($row->amount);
        if (bccomp($amount, '0', 2) <= 0) {
            throw FinancialException::withCode('reconciliation_invalid_entry', 'Journal amount must be positive; no passing report can be issued.');
        }
        $totals[$row->type] = bcadd($totals[$row->type], $amount, 2);
    }

    private function decimal(mixed $amount): string
    {
        if ((! is_string($amount) && ! is_int($amount)) || preg_match('/^-?\d+(?:\.\d{1,2})?$/D', (string) $amount) !== 1) {
            throw FinancialException::withCode('reconciliation_invalid_amount', 'Accounting amount is not an exact decimal; no passing report can be issued.');
        }

        return (string) $amount;
    }
}
