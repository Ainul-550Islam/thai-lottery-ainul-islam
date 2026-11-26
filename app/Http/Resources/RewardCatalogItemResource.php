<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\RewardCatalogItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RewardCatalogItem
 */
final class RewardCatalogItemResource extends JsonResource
{
    private int $claimedByMember = 0;

    private ?int $memberPointsBalance = null;

    private bool $gradeEligible = true;

    public function withMemberContext(int $claimedCount, int $pointsBalance, bool $gradeEligible = true): self
    {
        $this->claimedByMember = max(0, $claimedCount);
        $this->memberPointsBalance = max(0, $pointsBalance);
        $this->gradeEligible = $gradeEligible;

        return $this;
    }

    public function withMemberClaimCount(int $claimedCount): self
    {
        $this->claimedByMember = max(0, $claimedCount);

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var RewardCatalogItem $item */
        $item = $this->resource;
        $limit = $item->per_member_limit;
        $remainingForMember = $limit !== null ? max(0, $limit - $this->claimedByMember) : null;
        $affordable = $this->memberPointsBalance !== null
            ? $this->memberPointsBalance >= (int) $item->points_cost
            : true;
        $withinMemberLimit = $remainingForMember === null || $remainingForMember > 0;
        $inStock = $item->hasStockAvailable();

        return [
            'id' => (int) $item->getKey(),
            'code' => $item->code,
            'name' => $item->name,
            'description' => $item->description,
            'type' => $item->type->value,
            'points_cost' => (int) $item->points_cost,
            'payload' => $item->payload,
            'stock' => $item->remainingStock(),
            'in_stock' => $inStock,
            'per_member_limit' => $limit,
            'member_claimed_count' => $this->claimedByMember,
            'member_remaining_limit' => $remainingForMember,
            'affordable' => $affordable,
            'grade_eligible' => $this->gradeEligible,
            'redeemable' => $inStock && $withinMemberLimit && $affordable && $this->gradeEligible && (bool) $item->active,
            'starts_at' => $item->starts_at?->toIso8601String(),
            'ends_at' => $item->ends_at?->toIso8601String(),
            'active' => (bool) $item->active,
        ];
    }
}
