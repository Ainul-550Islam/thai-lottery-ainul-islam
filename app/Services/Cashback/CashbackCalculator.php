<?php

declare(strict_types=1);

namespace App\Services\Cashback;

use App\Services\Finance\Money;

/**
 * Pure cashback calculator: net real-money loss x rate (+boost bps) -> amount.
 * Contains zero DB queries and zero wallet mutations.
 */
final class CashbackCalculator
{
    public function netLossFromTotals(string $settledStake, string $settledWinnings): string
    {
        Money::assertExactArithmeticIsAvailable();

        $net = bcsub(
            bcadd($settledStake, '0.00', 2),
            bcadd($settledWinnings, '0.00', 2),
            2,
        );

        return bccomp($net, '0.00', 2) > 0 ? $net : '0.00';
    }

    /**
     * accrual = floor(basis * (policy.rate_bps + active_boost_bps) / 10000), capped by max_per_period.
     */
    public function calculateAccrualAmount(
        string $basisAmount,
        int $policyRateBps,
        int $activeBoostBps,
        string $maxPerPeriod,
    ): string {
        Money::assertExactArithmeticIsAvailable();

        $normalisedBasis = bcadd($basisAmount, '0.00', 2);
        if (bccomp($normalisedBasis, '0.00', 2) <= 0) {
            return '0.00';
        }

        $effectiveBps = max(0, $policyRateBps) + max(0, $activeBoostBps);
        if ($effectiveBps <= 0) {
            return '0.00';
        }

        // bcdiv truncates positive values at scale 2, producing exact minor-unit floor.
        $rawAccrual = bcdiv(bcmul($normalisedBasis, (string) $effectiveBps, 4), '10000', 2);
        $cap = bcadd($maxPerPeriod, '0.00', 2);

        if (bccomp($cap, '0.00', 2) > 0 && bccomp($rawAccrual, $cap, 2) > 0) {
            return $cap;
        }

        return $rawAccrual;
    }

    public function isClaimableAmount(string $claimableAmount, string $minClaim): bool
    {
        Money::assertExactArithmeticIsAvailable();

        $normalisedClaimable = bcadd($claimableAmount, '0.00', 2);
        $normalisedMin = bcadd($minClaim, '0.00', 2);

        return bccomp($normalisedClaimable, '0.00', 2) > 0
            && bccomp($normalisedClaimable, $normalisedMin, 2) >= 0;
    }
}
