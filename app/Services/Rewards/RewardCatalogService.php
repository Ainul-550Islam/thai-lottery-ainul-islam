<?php

declare(strict_types=1);

namespace App\Services\Rewards;

use App\Enums\AccountGradeLevel;
use App\Enums\RewardClaimStatus;
use App\Models\RewardCatalogItem;
use App\Models\RewardClaim;
use App\Models\User;
use App\Services\Account\AccountGradeEvaluator;
use App\Services\Loyalty\LoyaltyPointsService;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

final class RewardCatalogService
{
    public function __construct(
        private readonly LoyaltyPointsService $loyaltyPoints,
        private readonly AccountGradeEvaluator $gradeEvaluator,
    ) {}

    /**
     * @return array{
     *     points_balance: int,
     *     member_grade: AccountGradeLevel,
     *     items: Collection<int, RewardCatalogItem>,
     *     member_claim_counts: array<int, int>
     * }
     */
    public function catalogFor(User $member, ?CarbonInterface $now = null): array
    {
        $pointInTime = $now ?? Carbon::now();
        $memberGrade = $this->gradeEvaluator->evaluate($member, $pointInTime)->level;

        $items = RewardCatalogItem::query()
            ->available($pointInTime)
            ->orderBy('points_cost')
            ->orderBy('id')
            ->get();

        $counts = RewardClaim::query()
            ->where('member_id', $member->getKey())
            ->whereIn('status', [
                RewardClaimStatus::Pending->value,
                RewardClaimStatus::Completed->value,
            ])
            ->selectRaw('item_id, COUNT(*) as aggregate_count')
            ->groupBy('item_id')
            ->pluck('aggregate_count', 'item_id');

        $claimCounts = [];
        foreach ($counts as $itemId => $count) {
            $claimCounts[(int) $itemId] = (int) $count;
        }

        return [
            'points_balance' => $this->loyaltyPoints->currentBalance($member),
            'member_grade' => $memberGrade,
            'items' => $items,
            'member_claim_counts' => $claimCounts,
        ];
    }

    public function isGradeEligible(RewardCatalogItem $item, AccountGradeLevel $memberGrade): bool
    {
        $payload = is_array($item->payload) ? $item->payload : [];
        $minGradeKey = $payload['min_grade'] ?? null;
        if (! is_string($minGradeKey) || trim($minGradeKey) === '') {
            return true;
        }

        $required = AccountGradeLevel::fromKeyOrDefault(trim($minGradeKey));

        return $memberGrade->sl() >= $required->sl();
    }

    /**
     * @return LengthAwarePaginator<RewardClaim>
     */
    public function historyFor(User $member, int $perPage = 20): LengthAwarePaginator
    {
        return RewardClaim::query()
            ->where('member_id', $member->getKey())
            ->with(['item', 'voucher'])
            ->orderByDesc('id')
            ->paginate(max(1, min(100, $perPage)));
    }
}
