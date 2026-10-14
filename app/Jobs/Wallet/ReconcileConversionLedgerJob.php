<?php

declare(strict_types=1);

namespace App\Jobs\Wallet;

use App\Enums\QueueName;
use App\Enums\WalletConversionReconciliationState;
use App\Models\LedgerEntry;
use App\Models\WalletConversion;
use App\Repositories\Wallet\WalletConversionRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Checks that completed conversions and the ledger tell the same story.
 *
 * WHAT IS BEING COMPARED, AND WHY EXACTLY THIS
 * For each completed conversion in the window, the ledger rows that reference it
 * must net to the movement the conversion recorded: the source wallet down by
 * the amount converted, the destination wallet up by the amount received, and
 * the fee sitting in the fee-revenue account. The comparison is done with bcmath
 * on the stored strings — never with floats — because a rounding artefact in the
 * checker would produce a mismatch report that costs an operator an afternoon
 * and finds nothing.
 *
 * WHY IT NEVER REPAIRS ANYTHING
 * Every reconciliation tool that mutates is one incident away from being the
 * incident. If a conversion is genuinely wrong, the correction is a reversal or
 * a manual journal, both of which are deliberate, reasoned, audited acts. This
 * job's entire output is evidence: which references are balanced, which are
 * still settling, and which disagree — and a non-zero exit for the caller.
 *
 * WHY `Pending` IS A FIRST-CLASS OUTCOME AND NOT A FAILURE
 * A conversion completed seconds ago may legitimately have ledger rows still in
 * flight if a future change posts asynchronously. Reporting those as mismatches
 * would train operators to ignore the report; reporting them separately keeps
 * the mismatch count meaningful. Staleness beyond `stale_after_minutes` turns a
 * Pending into a Mismatch, because a movement that has not settled in half an
 * hour is not in flight, it is broken.
 */
class ReconcileConversionLedgerJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $lookbackHours = 48,
        public readonly int $limit = 1000,
    ) {
        $this->onQueue(QueueName::Reconciliation->value);
    }

    /**
     * @return array{
     *     checked: int,
     *     balanced: int,
     *     pending: int,
     *     mismatched: int,
     *     mismatches: array<int, array<string, mixed>>
     * }
     */
    public function handle(WalletConversionRepository $conversions): array
    {
        $from = Carbon::now()->subHours(max(1, $this->lookbackHours))->toDateTimeImmutable();
        $to = Carbon::now()->toDateTimeImmutable();

        $rows = $conversions->listCompletedBetween($from, $to, $this->limit);

        $tolerance = (string) config('conversions.reconciliation.tolerance', '0.00');
        $staleAfterMinutes = (int) config('conversions.reconciliation.stale_after_minutes', 30);
        $maxMismatches = (int) config('conversions.reconciliation.max_mismatch_rows', 200);

        $balanced = 0;
        $pending = 0;
        $mismatched = 0;
        $mismatches = [];

        foreach ($rows as $conversion) {
            $state = $this->inspect($conversion, $tolerance, $staleAfterMinutes);

            if ($state === WalletConversionReconciliationState::Balanced) {
                $balanced++;

                continue;
            }

            if ($state === WalletConversionReconciliationState::Pending) {
                $pending++;

                continue;
            }

            // The COUNT is always complete; only the row-by-row evidence is
            // capped. Reporting the capped length as the count told an operator
            // with a 200-row cap that a night of 900 broken rows was 200 broken
            // rows — the one number they triage on was the one number that lied.
            $mismatched++;

            if (count($mismatches) < $maxMismatches) {
                $mismatches[] = [
                    'reference' => (string) $conversion->reference,
                    'conversion_id' => (int) $conversion->getKey(),
                    'amount_in' => (string) $conversion->amount_in,
                    'fee_amount' => (string) $conversion->fee_amount,
                    'amount_out' => (string) $conversion->amount_out,
                    'source_delta' => $this->walletDelta($conversion, (int) $conversion->from_wallet_id),
                    'destination_delta' => $this->walletDelta($conversion, (int) $conversion->to_wallet_id),
                    'fee_posted' => $this->feePosted($conversion),
                ];
            }
        }

        $report = [
            'checked' => $rows->count(),
            'balanced' => $balanced,
            'pending' => $pending,
            'mismatched' => $mismatched,
            'mismatch_rows_capped' => $mismatched > count($mismatches),
            'mismatches' => $mismatches,
        ];

        Log::info('conversion.reconciliation', $report + [
            'lookback_hours' => $this->lookbackHours,
            'tolerance' => $tolerance,
        ]);

        if ($report['mismatched'] > 0 && (bool) config('conversions.reconciliation.alert_on_mismatch', true)) {
            Log::critical('conversion.reconciliation_mismatch', [
                'count' => $report['mismatched'],
                'sample' => array_slice($report['mismatches'], 0, 5),
            ]);
        }

        return $report;
    }

    private function inspect(WalletConversion $conversion, string $tolerance, int $staleAfterMinutes): WalletConversionReconciliationState
    {
        $entries = LedgerEntry::query()
            ->where('reference_type', '=', 'wallet_conversion')
            ->where('reference_id', '=', (int) $conversion->getKey())
            ->get();

        if ($entries->isEmpty()) {
            // Nothing posted yet. Inside the settling window that is expected;
            // beyond it, the movement has been recorded but never posted.
            $settledAt = $conversion->completed_at ?? $conversion->created_at;

            $ageMinutes = $settledAt === null ? 0 : $settledAt->diffInMinutes(now());

            return $ageMinutes > $staleAfterMinutes
                ? WalletConversionReconciliationState::Mismatched
                : WalletConversionReconciliationState::Pending;
        }

        $sourceDelta = $this->walletDelta($conversion, (int) $conversion->from_wallet_id);
        $destinationDelta = $this->walletDelta($conversion, (int) $conversion->to_wallet_id);
        $feePosted = $this->feePosted($conversion);

        [$expectedSource, $expectedDestination, $expectedFee] = $this->expectations($conversion);

        $sourceOk = bccomp($sourceDelta, $expectedSource, 2) === 0;
        $destinationOk = bccomp($destinationDelta, $expectedDestination, 2) === 0;
        $feeOk = bccomp($feePosted, $expectedFee, 2) === 0;

        if ($sourceOk && $destinationOk && $feeOk) {
            return WalletConversionReconciliationState::Balanced;
        }

        // A drift inside the configured tolerance is treated as balanced: the
        // tolerance exists so that a future rounding policy is a configuration
        // change rather than an edit to this method.
        if (bccomp($tolerance, '0.00', 2) === 1
            && $this->withinTolerance($sourceDelta, $expectedSource, $tolerance)
            && $this->withinTolerance($destinationDelta, $expectedDestination, $tolerance)
            && $this->withinTolerance($feePosted, $expectedFee, $tolerance)) {
            return WalletConversionReconciliationState::Balanced;
        }

        return WalletConversionReconciliationState::Mismatched;
    }

    /**
     * What the ledger must show for this row: source delta, destination delta,
     * and the fee movement in the revenue account.
     *
     * WHY A REVERSAL HAS ITS OWN SHAPE
     * An ordinary conversion posts -amount_in to the source, amount_in minus the
     * fee to the destination, and the fee into revenue. A reversal is a different
     * animal: it returns the net that actually arrived (its own `amount_in`, by
     * design) AND refunds the ORIGINAL fee as a separate leg, so the destination
     * gains `amount_in + refunded fee`, and the revenue account is DEBITED by the
     * refund rather than credited by a charge.
     *
     * Judging a reversal by the ordinary shape reports every reversal as a
     * mismatch. That is not a harmless optimism: the nightly reconciliation is
     * the alarm an operator is supposed to trust, and a report that cries wolf on
     * every reversal is a report that gets ignored on the night it matters.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function expectations(WalletConversion $conversion): array
    {
        $amountIn = bcadd((string) $conversion->amount_in, '0', 2);

        if ($conversion->reversal_of_id === null) {
            $fee = bcadd((string) $conversion->fee_amount, '0', 2);

            return [bcsub('0', $amountIn, 2), bcsub($amountIn, $fee, 2), $fee];
        }

        $original = WalletConversion::query()->find((int) $conversion->reversal_of_id);

        // A reversal whose original cannot be found is reported against what it
        // does know rather than guessed at; the missing link is itself the
        // mismatch the report will show.
        $refund = $original instanceof WalletConversion
            ? bcadd((string) $original->fee_amount, '0', 2)
            : '0.00';

        return [bcsub('0', $amountIn, 2), bcadd($amountIn, $refund, 2), bcsub('0', $refund, 2)];
    }

    /**
     * The net effect the ledger recorded on one wallet, on its own liability
     * account. Debits reduce a liability wallet, credits increase it.
     */
    private function walletDelta(WalletConversion $conversion, int $walletId): string
    {
        $entries = LedgerEntry::query()
            ->where('reference_type', '=', 'wallet_conversion')
            ->where('reference_id', '=', (int) $conversion->getKey())
            ->where('wallet_id', '=', $walletId)
            ->whereHas('ledgerAccount', static fn ($query) => $query->where('code', 'like', '2000%'))
            ->get();

        $delta = '0.00';

        foreach ($entries as $entry) {
            $delta = (string) $entry->type->value === 'debit'
                ? bcsub($delta, (string) $entry->amount, 2)
                : bcadd($delta, (string) $entry->amount, 2);
        }

        return $delta;
    }

    /**
     * How much landed in the fee-revenue account for this conversion.
     */
    private function feePosted(WalletConversion $conversion): string
    {
        $entries = LedgerEntry::query()
            ->where('reference_type', '=', 'wallet_conversion')
            ->where('reference_id', '=', (int) $conversion->getKey())
            ->whereHas('ledgerAccount', static fn ($query) => $query->where('code', 'like', '4100%'))
            ->get();

        $total = '0.00';

        foreach ($entries as $entry) {
            $total = (string) $entry->type->value === 'debit'
                ? bcsub($total, (string) $entry->amount, 2)
                : bcadd($total, (string) $entry->amount, 2);
        }

        return $total;
    }

    private function withinTolerance(string $actual, string $expected, string $tolerance): bool
    {
        return bccomp(bcsub($actual, $expected, 2), $tolerance, 2) <= 0
            && bccomp(bcsub($expected, $actual, 2), $tolerance, 2) <= 0;
    }

    public function uniqueId(): string
    {
        return 'wallet-conversion:reconcile:'.$this->lookbackHours;
    }
}
