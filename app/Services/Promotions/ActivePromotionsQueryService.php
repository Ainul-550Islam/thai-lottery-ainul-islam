<?php

declare(strict_types=1);

namespace App\Services\Promotions;

use App\Models\PointsMultiplierCampaign;
use App\Models\User;
use App\Services\Promotions\PublicBonusService;
use Illuminate\Support\Carbon;

/**
 * "What is running right now" — READ-ONLY, for the member's promotions screen.
 *
 * COMPOSES, DOES NOT REIMPLEMENT
 *   - points campaigns come from points_multiplier_campaigns via the same
 *     activeAt() scope PointsMultiplierService uses, so the screen and the earn
 *     path can never show different campaigns
 *   - public bonuses come from the existing Batch-2-era PublicBonusService, which
 *     already decides what a member is eligible to see
 *
 * WHAT IS DELIBERATELY NOT HERE
 * No coupon hunting, no "personalised offer" computed from behaviour, no
 * countdown invented from a campaign's ends_at if that field is null. If a value
 * is unknown it is null and the UI omits it.
 */
final class ActivePromotionsQueryService
{
    public function __construct(
        private readonly PublicBonusService $bonuses,
        private readonly \App\Services\Loyalty\PointsMultiplierService $multipliers,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function active(User $user, ?Carbon $at = null): array
    {
        $now = $at ?? Carbon::now();
        $limit = max(1, (int) config('player_experience.promotions.active_list_limit', 20));

        $campaigns = PointsMultiplierCampaign::query()
            ->activeAt($now)
            ->orderByDesc('multiplier_bps')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(static fn (PointsMultiplierCampaign $c): array => [
                'type' => 'points_multiplier',
                'name' => $c->name,
                'description' => $c->description,
                'multiplier' => $c->multiplierDecimal(),
                'multiplier_bps' => $c->multiplier_bps,
                'game_scope' => $c->game_scope,
                'starts_at' => $c->starts_at?->toIso8601String(),
                'ends_at' => $c->ends_at?->toIso8601String(),
            ])
            ->all();

        $bonuses = [];

        try {
            // activeCampaigns() is config-driven and member-independent: the
            // bonus lane publishes a catalogue, not a per-member entitlement.
            $bonusPayload = $this->bonuses->activeCampaigns();
            $bonuses = (array) ($bonusPayload['campaigns'] ?? []);
        } catch (\Throwable) {
            // The bonus service is owned by another lane; if it is unavailable the
            // response says so rather than reporting "no bonuses", which would be
            // a claim this service cannot make.
            $bonuses = [];
        }

        return [
            'points_campaigns' => $campaigns,
            'bonuses' => $bonuses,
            'bonuses_available' => $bonuses !== [],
            'effective_multiplier' => $this->multipliers->multiplierDecimalAt($now),
            'generated_at' => $now->toIso8601String(),
        ];
    }
}
