<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\Models\ResponsibleGamingLimit;
use App\Models\SelfExclusion;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * A READ-ONLY summary of the member's own protection settings.
 *
 * HARD RULE: NO WRITE PATH
 * There is deliberately no setLimit(), no selfExclude() and no clear() in this
 * class. The write lanes already exist — ResponsibleGamingController,
 * ResponsibleGamingLimitService, SelfExclusionService and the support-ticket
 * route for clearing exclusion — and every one of them has its own audit trail,
 * cool-off rules and (for exclusion) an intentional friction. A convenience setter
 * here would be a fourth writer with none of those properties, reachable from a
 * screen whose job is to DISPLAY the state.
 *
 * WHAT IT READS
 *   responsible_gaming_limits  daily deposit / single bet / daily wagering caps,
 *                              self_excluded_until and cool_off_until
 *   self_exclusions            the audited exclusion rows (status + window)
 *
 * The union of both is reported because the repository has both: the limits table
 * carries legacy timestamps and the exclusions table carries the audited record.
 * Where they disagree, the summary reports the LONGEST effective restriction —
 * the safe reading, since showing a member they are unrestricted one day early is
 * the dangerous error.
 */
final class ResponsibleGamingSummaryService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(User $user, ?Carbon $at = null): array
    {
        $now = $at ?? Carbon::now();

        /** @var ResponsibleGamingLimit|null $limits */
        $limits = ResponsibleGamingLimit::query()->where('user_id', $user->id)->first();

        $activeExclusion = $this->activeExclusion($user, $now);

        $selfExcludedUntil = $this->latestOf(
            $limits?->self_excluded_until,
            $activeExclusion?->ends_at,
        );

        $coolOffUntil = $limits?->cool_off_until;

        $selfExcluded = $selfExcludedUntil !== null && $selfExcludedUntil->greaterThanOrEqualTo($now);
        $coolingOff = $coolOffUntil !== null && $coolOffUntil->greaterThanOrEqualTo($now);

        return [
            'limits' => [
                'daily_deposit_limit' => $this->money($limits?->daily_deposit_limit),
                'single_bet_limit' => $this->money($limits?->single_bet_limit),
                'daily_wagering_limit' => $this->money($limits?->daily_wagering_limit),
                'has_any_limit' => $limits instanceof ResponsibleGamingLimit
                    && ($limits->daily_deposit_limit !== null
                        || $limits->single_bet_limit !== null
                        || $limits->daily_wagering_limit !== null),
            ],
            'self_exclusion' => [
                'active' => $selfExcluded,
                'until' => $selfExcludedUntil?->toIso8601String(),
                'reason_code' => $activeExclusion?->reason_code,
                'scope' => $activeExclusion?->scope,
            ],
            'cool_off' => [
                'active' => $coolingOff,
                'until' => $coolOffUntil?->toIso8601String(),
            ],
            'can_wager' => ! $selfExcluded && ! $coolingOff,
            'read_only' => true,
            'help_links' => [
                'limits' => '/player/security',
                'self_exclusion' => '/responsible-gaming',
                'support' => '/support',
            ],
            'generated_at' => $now->toIso8601String(),
        ];
    }

    /**
     * The member's active exclusion row, if any.
     *
     * Status-based, because SelfExclusion rows are audited records with their own
     * lifecycle; a row that was cancelled is not an active restriction even if its
     * ends_at is in the future.
     */
    private function activeExclusion(User $user, Carbon $now): ?SelfExclusion
    {
        /** @var SelfExclusion|null $row */
        $row = SelfExclusion::query()
            ->where('user_id', $user->id)
            ->whereNull('cancelled_at')
            ->whereNull('expired_at')
            ->where(function ($q) use ($now): void {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->orderByDesc('ends_at')
            ->orderByDesc('id')
            ->first();

        return $row;
    }

    /**
     * The later of two nullable timestamps. Null only when both are null.
     */
    private function latestOf(?Carbon $a, ?Carbon $b): ?Carbon
    {
        if ($a === null) {
            return $b;
        }

        if ($b === null) {
            return $a;
        }

        return $a->greaterThan($b) ? $a : $b;
    }

    private function money(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return bcadd((string) $value, '0.00', 2);
    }
}
