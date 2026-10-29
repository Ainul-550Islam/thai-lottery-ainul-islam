<?php

declare(strict_types=1);

namespace App\Services\Risk;

use App\Exceptions\RiskException;
use App\Models\NumberLimit;
use Illuminate\Support\Facades\DB;

/** Additive durable minima for NEW owned purchases; never adopt legacy aggregate capacity. */
final class BetRiskOwnershipFloor
{
    public function assertCovered(NumberLimit $limit): void
    {
        // This floor answers ONE question: does the aggregate counter on this
        // number already cover every durable active purchase claim against it?
        //
        // It deliberately takes no proposed amount. An earlier version also
        // accepted $proposedStake/$proposedPayout and refused whenever the
        // proposed increment was smaller than the sum of the EXISTING claims:
        //
        //     bccomp($proposedStake, $stake, 2) < 0
        //
        // That comparison is not a coverage test, it is a category error. It
        // asks "is this new sale at least as large as everything already sold on
        // this number?", so a second 10.00 purchase on a number that already
        // carried a 10.00 claim was refused forever, and no number could ever
        // take two equal sales. It made every multi-purchase reservation path
        // (single, many, facade) fail with bet_risk_ownership_conflict.
        //
        // Coverage is invariant under the increment: if the counter is short
        // BEFORE the sale it is still short after it, because the sale adds the
        // same amount to both sides. So the pre-increment check below is the
        // whole control, and "before the proposed increment" is satisfied by
        // reading the counter here rather than after the engine applies it.
        if (DB::transactionLevel() < 1) {
            $this->refuse();
        }
        $current = DB::table('number_limits')->where('id', $limit->id)
            ->selectRaw('CAST(current_amount AS CHAR) AS stake, CAST(current_payout_exposure AS CHAR) AS payout')
            ->lockForUpdate()->first();
        $claims = DB::table('bet_purchase_ownerships')->where('number_limit_id', $limit->id)->where('state', 'active')
            ->orderBy('id')->lockForUpdate()->get();
        $stake = '0.00';
        $payout = '0.00';
        foreach ($claims as $claim) {
            if ($claim->currency !== strtoupper((string) config('lottery.betting.currency'))
                || $claim->refund_transaction_id !== null || $claim->cancelled_at !== null
                || ! $this->positive($claim->stake_amount) || ! $this->positive($claim->potential_payout)) {
                $this->refuse();
            }
            $stake = bcadd($stake, $claim->stake_amount, 2);
            $payout = bcadd($payout, $claim->potential_payout, 2);
        }
        // A new sale must not hide an existing ownership deficit by replenishing
        // the aggregate counter, so the counter is compared against the durable
        // claims BEFORE anything is added to it. A missing locked row is itself a
        // deficit: there is nothing to prove coverage against.
        if (! $current
            || bccomp((string) $current->stake, $stake, 2) < 0
            || bccomp((string) $current->payout, $payout, 2) < 0) {
            $this->refuse();
        }
    }

    private function positive(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A(?:0|[1-9][0-9]{0,17})\.[0-9]{2}\z/D', $value)
            && bccomp($value, '0.00', 2) > 0;
    }

    private function refuse(): never
    {
        throw RiskException::withCode('bet_risk_ownership_conflict', 'Risk counters must cover every durable active purchase; controlled review is required.');
    }
}
