<?php

declare(strict_types=1);

namespace App\Services\Rewards;

use App\Models\User;
use App\Services\Account\AccountGradeEvaluator;
use App\Services\Loyalty\LoyaltyPointsService;
use DateTimeInterface;

final class VipProgressService
{
    public function __construct(
        private readonly AccountGradeEvaluator $gradeEvaluator,
        private readonly LoyaltyPointsService $loyaltyPoints,
    ) {}

    /**
     * @return array{
     *     level: string,
     *     points: int,
     *     next_level: string|null,
     *     points_needed: int,
     *     spend_needed: string,
     *     current_grade: array{key: string, name: string, level: string, sl: int, is_base: bool},
     *     next_grade: array{key: string, name: string, sl: int, min_spend: string, spend_remaining: string}|null,
     *     qualifying_spend: array{amount: string, currency: string, window_days: int, window_start: string, window_end: string},
     *     progress_percent: string,
     *     loyalty_points_balance: int,
     *     discount_rate: string,
     *     evaluated_at: string
     * }
     */
    public function progressFor(User $member, ?DateTimeInterface $asOf = null): array
    {
        $evaluation = $this->gradeEvaluator->evaluate($member, $asOf);
        $next = $this->gradeEvaluator->nextTierFor($member, $asOf);
        $pointsBalance = $this->loyaltyPoints->currentBalance($member);

        $progressPercent = '100.00';
        $nextGradePayload = null;
        $nextLevelKey = null;
        $spendNeeded = '0.00';
        $pointsNeeded = 0;

        if ($next['tier'] !== null) {
            $minSpend = bcadd((string) $next['tier']->minSpend, '0.00', 2);
            $currentSpend = bcadd((string) $evaluation->qualifyingSpend, '0.00', 2);
            $spendNeeded = bcadd((string) $next['remaining'], '0.00', 2);
            $nextLevelKey = $next['tier']->key;

            [$whole, $fraction] = array_pad(explode('.', $spendNeeded, 2), 2, '00');
            $pointsNeeded = max(0, (int) $whole + ((int) $fraction > 0 ? 1 : 0));

            if (bccomp($minSpend, '0.00', 2) > 0) {
                $ratio = bcdiv(bcmul($currentSpend, '100', 4), $minSpend, 2);
                $progressPercent = bccomp($ratio, '100.00', 2) > 0 ? '100.00' : $ratio;
            }

            $nextGradePayload = [
                'key' => $next['tier']->key,
                'name' => $next['tier']->name,
                'sl' => $next['tier']->sl,
                'min_spend' => $minSpend,
                'spend_remaining' => $spendNeeded,
            ];
        }

        return [
            'level' => $evaluation->level->value,
            'points' => $pointsBalance,
            'next_level' => $nextLevelKey,
            'points_needed' => $pointsNeeded,
            'spend_needed' => $spendNeeded,
            'current_grade' => [
                'key' => $evaluation->tier->key,
                'name' => $evaluation->tier->name,
                'level' => $evaluation->level->value,
                'sl' => $evaluation->tier->sl,
                'is_base' => $evaluation->level->isBase(),
            ],
            'next_grade' => $nextGradePayload,
            'qualifying_spend' => [
                'amount' => bcadd((string) $evaluation->qualifyingSpend, '0.00', 2),
                'currency' => (string) config('account_grades.currency', 'THB'),
                'window_days' => $evaluation->windowDays,
                'window_start' => $evaluation->windowStart,
                'window_end' => $evaluation->windowEnd,
            ],
            'progress_percent' => $progressPercent,
            'loyalty_points_balance' => $pointsBalance,
            'discount_rate' => (string) $evaluation->appliedRate,
            'evaluated_at' => $evaluation->evaluatedAt,
        ];
    }
}
