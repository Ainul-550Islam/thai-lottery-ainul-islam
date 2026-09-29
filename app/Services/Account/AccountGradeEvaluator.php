<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\DTOs\Account\GradeEvaluationResult;
use App\Enums\AccountGradeLevel;
use App\Models\AccountGradeSnapshot;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * THE canonical grade evaluator (PROMPT 2, section C).
 *
 * This is the one place that turns "a user, at an instant" into a
 * GradeEvaluationResult. Both the account-grade API, the authenticated
 * grade page and the snapshot rebuild command read it, so every
 * surface that shows a grade shows the SAME grade.
 *
 * THE WINDOW IS EXACT. The rolling window is asOf minus exactly
 * grade_period_days days, to the second, in the application timezone.
 * No calendar-month approximation, no "30 days ago at midnight": a
 * bet placed one second outside the window is outside the window.
 *
 * THE SPEND SOURCE IS DELEGATED, NEVER DUPLICATED. Qualifying spend is
 * whatever the existing AccountGradeService::qualifyingSpend(), which reads the
 * authoritative BetPlacement/Completed/non-reversed transactions -
 * never deposits, never client figures. This class adds the window
 * discipline, the tier resolution and the projection; it does not
 * re-implement the ledger's definition of spend.
 *
 * NOTHING HERE MUTATES A BALANCE. Evaluation is a pure projection; the
 * append-only snapshot persistence lives in the rebuild command and
 * the existing recalculate path.
 */
final class AccountGradeEvaluator
{
    /** Bumped when the evaluation algorithm itself changes shape. */
    public const SOURCE_VERSION = '2';

    public function __construct(
        private readonly AccountGradeService $spend,
        private readonly GradeTierCatalog $catalog,
        private readonly GradeDiscountEntitlementService $entitlements,
    ) {}

    /**
     * Evaluate a user's grade.
     *
     * @param  \DateTimeInterface|null  $asOf  pin the evaluation instant
     *                                        (testing/auditing); defaults to now
     */
    public function evaluate(User $user, ?\DateTimeInterface $asOf = null): GradeEvaluationResult
    {
        $asOf = $asOf !== null ? Carbon::instance($asOf) : Carbon::now($this->timezone());
        $days = max(1, (int) config('account_grades.grade_period_days', 30));

        // The EXACT rolling window: same instant minus exactly N days.
        $windowStart = $asOf->copy()->subDays($days);
        $windowEnd = $asOf->copy();

        // Authoritative spend — delegated, never duplicated.
        $qualifyingSpend = $this->spend->qualifyingSpend($user, $windowStart, $windowEnd);

        $tier = $this->catalog->tierForSpend($qualifyingSpend, $asOf);
        $previous = $this->latestSnapshotLevel($user);

        return new GradeEvaluationResult(
            userId: (int) $user->id,
            windowStart: $windowStart->toDateTimeString(),
            windowEnd: $windowEnd->toDateTimeString(),
            windowDays: $days,
            qualifyingSpend: bcadd($qualifyingSpend, '0', 2),
            level: $tier->level,
            tier: $tier,
            previousLevel: $previous,
            appliedRate: $tier->discountRate,
            eligibleGames: $this->entitlements->eligibleGames($tier),
            entitlementHash: $this->entitlements->entitlementHash($tier),
            ruleVersion: (string) config('account_grades.rule_version', '1'),
            evaluatedAt: $asOf->toDateTimeString(),
            sourceVersion: self::SOURCE_VERSION,
        );
    }

    /**
     * The next tier above the user's current one, with the spend still
     * required to reach it ('0.00' at the top of the ladder).
     *
     * @return array{tier: \App\DTOs\Account\GradeTier|null, remaining: string}
     */
    public function nextTierFor(User $user, ?\DateTimeInterface $asOf = null): array
    {
        $result = $this->evaluate($user, $asOf);
        $next = $this->catalog->nextTierAfter($result->level, $asOf);

        return [
            'tier' => $next,
            'remaining' => $result->spendRemainingToNext($next),
        ];
    }

    /**
     * The user's most recent stored snapshot level, or null when none
     * exists. Historical rows from retired tiers resolve defensively to
     * the base state so an old key can never crash a live evaluation.
     */
    private function latestSnapshotLevel(User $user): ?AccountGradeLevel
    {
        $latest = AccountGradeSnapshot::query()
            ->where('user_id', $user->id)
            ->orderByDesc('calculated_at')
            ->orderByDesc('id')
            ->first();

        if ($latest === null) {
            return null;
        }

        return AccountGradeLevel::fromKeyOrDefault((string) $latest->grade_key);
    }

    /**
     * The timezone the window discipline runs in.
     */
    private function timezone(): \DateTimeZone
    {
        return new \DateTimeZone((string) config('app.timezone') ?: 'UTC');
    }
}
