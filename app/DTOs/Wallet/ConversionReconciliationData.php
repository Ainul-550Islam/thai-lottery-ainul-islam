<?php

declare(strict_types=1);

namespace App\DTOs\Wallet;

use App\Enums\WalletConversionReconciliationState;
use DateTimeImmutable;

/**
 * The result of one reconciliation pass over wallet conversions.
 *
 * WHY IT REPORTS VOLUMES AND FEES, NOT JUST COUNTS
 * "412 conversions reconciled" is not an answer an accountant can use. The
 * question that matters after a settlement window is "did the money that left
 * the Cash wallets equal the money that arrived in the Win wallets plus the fee
 * revenue we recorded?" — which needs sums, not counts. This object therefore
 * carries the inflow total, the outflow total and the fee total so the three
 * can be checked against each other and against the ledger, and so a drift of a
 * single satang is visible rather than rounded away.
 *
 * The `mismatches` list is deliberately bounded by the caller (the reconciler
 * caps it) because an unbounded list of broken rows is itself an outage.
 *
 * All monetary fields are exact decimal strings.
 */
final readonly class ConversionReconciliationData
{
    /**
     * @param  array<int, array<string, mixed>>  $mismatches
     * @param  array<int, array<string, mixed>>  $pending
     */
    public function __construct(
        public WalletConversionReconciliationState $state,
        public string $currency,
        public int $completedCount,
        public int $pendingCount,
        public int $reversedCount,
        public int $failedCount,
        public string $volumeIn,
        public string $volumeOut,
        public string $feeTotal,
        public DateTimeImmutable $generatedAt,
        public array $mismatches = [],
        public array $pending = [],
        public ?DateTimeImmutable $windowFrom = null,
        public ?DateTimeImmutable $windowTo = null,
    ) {}

    /**
     * True when the completed conversions reconcile: the amount that left the
     * source wallets equals the amount that reached the destination wallets
     * plus the fee revenue that was recorded.
     *
     * This is the accounting identity a conversion must always satisfy —
     * money is never created or destroyed, only split between player balances
     * and the fee account.
     */
    public function balancesArithmetically(): bool
    {
        return bccomp(
            bcsub($this->volumeIn, $this->volumeOut, 2),
            $this->feeTotal,
            2,
        ) === 0;
    }

    public function isHealthy(): bool
    {
        return $this->state->isHealthy() && $this->balancesArithmetically();
    }

    /**
     * A one-line status for the admin page header and the command output.
     */
    public function describe(): string
    {
        return sprintf(
            '%s: %d completed, %d pending, %d reversed, %d failed; in %s %s, out %s %s, fees %s %s.',
            $this->state->label(),
            $this->completedCount,
            $this->pendingCount,
            $this->reversedCount,
            $this->failedCount,
            $this->volumeIn,
            $this->currency,
            $this->volumeOut,
            $this->currency,
            $this->feeTotal,
            $this->currency,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'state_label' => $this->state->label(),
            'currency' => $this->currency,
            'completed_count' => $this->completedCount,
            'pending_count' => $this->pendingCount,
            'reversed_count' => $this->reversedCount,
            'failed_count' => $this->failedCount,
            'volume_in' => $this->volumeIn,
            'volume_out' => $this->volumeOut,
            'fee_total' => $this->feeTotal,
            'balances_arithmetically' => $this->balancesArithmetically(),
            'is_healthy' => $this->isHealthy(),
            'generated_at' => $this->generatedAt->format(DATE_ATOM),
            'window_from' => $this->windowFrom?->format(DATE_ATOM),
            'window_to' => $this->windowTo?->format(DATE_ATOM),
            'mismatches' => $this->mismatches,
            'pending' => $this->pending,
        ];
    }
}
