<?php

declare(strict_types=1);

namespace App\Services\Loyalty;

use App\Models\PointsMultiplierCampaign;
use Illuminate\Support\Carbon;

/**
 * The effective points multiplier at a moment in time. A PURE LOOKUP.
 *
 * NO WRITES, NO CACHE, NO SIDE EFFECTS
 * It reads points_multiplier_campaigns and answers a number. It does not award
 * points, does not touch the points ledger and does not remember anything between
 * calls — which is what makes it safe to call from inside the earn transaction
 * without any risk of a nested write.
 *
 * THE STACKING RULE COMES FROM CONFIG
 *   highest  the single highest active multiplier applies (the default)
 *   none     campaign multipliers are ignored entirely, so points are exactly
 *            what the pre-Batch-3 code produced
 * Multipliers never multiply each other. The reason is auditability: if 1.5x and
 * 2x could compose, the points a member received on a given day could no longer
 * be recomputed from the campaigns table alone, and the recorded multiplier would
 * be a number nobody could reproduce.
 *
 * INTEGER BASIS POINTS ALL THE WAY
 * Everything is bps. The one division (points * bps / 10000) happens once, in
 * effectivePoints(), with intdiv — i.e. it floors. Flooring is deliberate: a
 * campaign may never hand out a point that the base formula did not earn.
 */
final class PointsMultiplierService
{
    public const STACKING_HIGHEST = 'highest';
    public const STACKING_NONE = 'none';

    /**
     * The winning campaign at $at, or null when none applies.
     */
    public function activeCampaignAt(
        ?Carbon $at = null,
        ?string $gameCode = null,
        ?string $tierKey = null,
    ): ?PointsMultiplierCampaign {
        if ($this->stacking() === self::STACKING_NONE) {
            return null;
        }

        $moment = $at ?? Carbon::now();

        /** @var PointsMultiplierCampaign|null $campaign */
        $campaign = PointsMultiplierCampaign::query()
            ->activeAt($moment)
            ->forGame($gameCode)
            ->forTier($tierKey)
            ->orderByDesc('multiplier_bps')
            ->orderBy('id')
            ->first();

        return $campaign;
    }

    /**
     * The effective multiplier for this earn, as bps. 10000 = 1.0x (no change).
     */
    public function multiplierBpsAt(
        ?Carbon $at = null,
        ?string $gameCode = null,
        ?string $tierKey = null,
    ): int {
        $campaign = $this->activeCampaignAt($at, $gameCode, $tierKey);

        if (! $campaign instanceof PointsMultiplierCampaign) {
            return 10000;
        }

        $bps = (int) $campaign->multiplier_bps;

        // Guard against a campaign above the configured ceiling (a typo in a
        // form, a hand-edited row). Clamping is preferable to refusing the whole
        // earn: the member still gets their base points.
        $ceiling = (int) config('player_experience.loyalty.max_multiplier_bps', 50000);

        return max(1, min($bps, $ceiling));
    }

    /**
     * The exact decimal multiplier ('1.5000'). For display and for the audit
     * column; the integer path uses multiplierBpsAt().
     */
    public function multiplierDecimalAt(
        ?Carbon $at = null,
        ?string $gameCode = null,
        ?string $tierKey = null,
    ): string {
        return bcdiv((string) $this->multiplierBpsAt($at, $gameCode, $tierKey), '10000', 4);
    }

    /**
     * Apply the multiplier to a base points figure.
     *
     * Floors: 33 points at 1.5x is 49 (not 49.5 and not 50). A campaign is an
     * advertised benefit, and rounding it up would make the ledger disagree with
     * the formula that produced it.
     */
    public function effectivePoints(int $basePoints, ?Carbon $at = null, ?string $gameCode = null, ?string $tierKey = null): int
    {
        if ($basePoints <= 0) {
            return 0;
        }

        $bps = $this->multiplierBpsAt($at, $gameCode, $tierKey);

        if ($bps === 10000) {
            // Exact no-op path: identical integer, no arithmetic at all, so the
            // regression test can assert byte-identical behaviour when no
            // campaign is active.
            return $basePoints;
        }

        return max(0, intdiv($basePoints * $bps, 10000));
    }

    /**
     * A compact audit stamp recorded on the points ledger row, so a later
     * investigation can see WHICH campaign produced a given multiplier.
     *
     * @return array<string, mixed>|null null when no campaign applied
     */
    public function auditStampAt(?Carbon $at = null, ?string $gameCode = null, ?string $tierKey = null): ?array
    {
        $campaign = $this->activeCampaignAt($at, $gameCode, $tierKey);

        if (! $campaign instanceof PointsMultiplierCampaign) {
            return null;
        }

        return [
            'campaign_id' => (int) $campaign->id,
            'campaign_slug' => (string) $campaign->slug,
            'multiplier_bps' => (int) $campaign->multiplier_bps,
            'stacking' => $this->stacking(),
        ];
    }

    public function stacking(): string
    {
        $value = (string) config('player_experience.promotions.multiplier_stacking', self::STACKING_HIGHEST);

        return in_array($value, [self::STACKING_HIGHEST, self::STACKING_NONE], true)
            ? $value
            : self::STACKING_HIGHEST;
    }
}
