<?php

declare(strict_types=1);

namespace App\Services\Risk;

use App\Exceptions\RiskException;
use App\Models\NumberLimit;
use Illuminate\Support\Facades\DB;

/** Additive durable minima for NEW owned purchases; never adopt legacy aggregate capacity. */
final class BetRiskOwnershipFloor
{
    public function assertCovered(NumberLimit $limit, ?string $proposedStake = null, ?string $proposedPayout = null): void
    {
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
        // Check BEFORE the proposed increment as well: a new sale must not hide
        // an existing ownership deficit by replenishing the aggregate counter.
        if (! $current || bccomp((string) $current->stake, $stake, 2) < 0 || bccomp((string) $current->payout, $payout, 2) < 0
            || ($proposedStake !== null && bccomp($proposedStake, $stake, 2) < 0)
            || ($proposedPayout !== null && bccomp($proposedPayout, $payout, 2) < 0)) {
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
