<?php

declare(strict_types=1);

namespace App\DTOs\Finance;

use App\Enums\Currency;
use App\Enums\ReconciliationStatus;
use Carbon\CarbonInterface;

final readonly class FinancialReconciliationReport
{
    /**
     * @param  list<ReconciliationDiscrepancy>  $discrepancies
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $executionId,
        public ReconciliationStatus $status,
        public ?CarbonInterface $periodStart,
        public ?CarbonInterface $periodEnd,
        public ?Currency $currency,
        public string $totalDeposits,
        public string $totalBetPurchases,
        public string $totalPrizePayouts,
        public string $totalWithdrawals,
        public string $totalCommissions,
        public string $totalLedgerDebits,
        public string $totalLedgerCredits,
        public string $ledgerDifference,
        public array $discrepancies,
        public int $anomalyCount,
        public int $criticalAnomalyCount,
        public CarbonInterface $executedAt,
        public ?string $initiatedBy = null,
        public array $metadata = [],
        public float $durationSeconds = 0.0,
    ) {
        // Derived in the constructor rather than promoted, so that every
        // existing construction site keeps working unchanged while the report
        // still always carries a summary. A reconciliation report that cannot
        // state its own outcome in one line is of no use to the operator who
        // is paged at 03:00 to read it.
        $this->summary = sprintf(
            '%s | %s | %s | ledger difference %s | %d anomal%s (%d critical) | %d discrepanc%s',
            $this->executionId,
            $this->status->value,
            $this->currency?->value ?? 'ALL',
            $this->ledgerDifference,
            $this->anomalyCount,
            $this->anomalyCount === 1 ? 'y' : 'ies',
            $this->criticalAnomalyCount,
            count($this->discrepancies),
            count($this->discrepancies) === 1 ? 'y' : 'ies',
        );
    }

    /**
     * One-line statement of what this reconciliation found.
     *
     * The service had a describe() method, but the report itself could not
     * answer the question, so anything holding only the report - a queued job,
     * a notification, a stored audit row - had no way to say what happened.
     */
    public string $summary;

    public function isPassing(): bool
    {
        return $this->status->isPassing();
    }

    public function isCritical(): bool
    {
        return $this->status->isCritical();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'execution_id' => $this->executionId,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'period_start' => $this->periodStart?->toIso8601String(),
            'period_end' => $this->periodEnd?->toIso8601String(),
            'currency' => $this->currency?->value,
            'totals' => [
                'deposits' => $this->totalDeposits,
                'bet_purchases' => $this->totalBetPurchases,
                'prize_payouts' => $this->totalPrizePayouts,
                'withdrawals' => $this->totalWithdrawals,
                'commissions' => $this->totalCommissions,
                'ledger_debits' => $this->totalLedgerDebits,
                'ledger_credits' => $this->totalLedgerCredits,
                'ledger_difference' => $this->ledgerDifference,
            ],
            'anomaly_count' => $this->anomalyCount,
            'critical_anomaly_count' => $this->criticalAnomalyCount,
            'discrepancies' => array_map(
                static fn (ReconciliationDiscrepancy $d): array => $d->toArray(),
                $this->discrepancies,
            ),
            'executed_at' => $this->executedAt->toIso8601String(),
            'initiated_by' => $this->initiatedBy,
            'metadata' => $this->metadata,
        ];
    }
}
