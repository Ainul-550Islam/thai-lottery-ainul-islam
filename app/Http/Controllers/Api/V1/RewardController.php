<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\FinancialException;
use App\Http\Requests\Api\V1\ClaimRewardRequest;
use App\Http\Resources\RewardCatalogItemResource;
use App\Http\Resources\RewardClaimResource;
use App\Http\Responses\ApiResponse;
use App\Models\RewardCatalogItem;
use App\Models\RewardClaim;
use App\Models\User;
use App\Services\Rewards\RewardCatalogService;
use App\Services\Rewards\RewardRedemptionService;
use App\Services\Rewards\VipProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class RewardController
{
    public function __construct(
        private readonly RewardCatalogService $catalog,
        private readonly RewardRedemptionService $redemptions,
        private readonly VipProgressService $vipProgress,
    ) {}

    public function catalog(Request $request): JsonResponse
    {
        /** @var User $member */
        $member = $request->user();
        $result = $this->catalog->catalogFor($member);

        $items = $result['items']->map(function (RewardCatalogItem $item) use ($request, $result): array {
            $claimed = $result['member_claim_counts'][(int) $item->getKey()] ?? 0;
            $gradeEligible = $this->catalog->isGradeEligible($item, $result['member_grade']);

            return (new RewardCatalogItemResource($item))
                ->withMemberContext($claimed, $result['points_balance'], $gradeEligible)
                ->resolve($request);
        })->values()->all();

        return ApiResponse::success([
            'points_balance' => $result['points_balance'],
            'member_grade' => $result['member_grade']->value,
            'items' => $items,
        ], 'Reward catalog retrieved.');
    }

    public function claim(ClaimRewardRequest $request, string $item): JsonResponse
    {
        /** @var User $member */
        $member = $request->user();

        try {
            $outcome = $this->redemptions->redeem($member, $item, $request->idempotencyKey());
        } catch (FinancialException $exception) {
            $status = $exception->errorCode() === 'reward_item_not_found' ? 404 : 422;

            return ApiResponse::error(
                code: $exception->errorCode() ?? 'reward_error',
                message: $exception->getMessage(),
                status: $status,
            );
        }

        $claimPayload = (new RewardClaimResource($outcome['claim']))->resolve($request);

        return ApiResponse::success(
            data: [
                'claim' => $claimPayload,
                'replayed' => $outcome['replayed'],
            ],
            message: $outcome['replayed'] ? 'Reward claim replayed.' : 'Reward claimed successfully.',
            status: $outcome['replayed'] ? 200 : 201,
        );
    }

    public function history(Request $request): JsonResponse
    {
        /** @var User $member */
        $member = $request->user();
        Gate::forUser($member)->authorize('viewAny', RewardClaim::class);
        $perPage = (int) $request->integer('per_page', 20);
        $paginator = $this->catalog->historyFor($member, $perPage);

        /** @var list<RewardClaim> $rawItems */
        $rawItems = $paginator->items();
        $items = array_map(
            static fn (RewardClaim $claim): array => (new RewardClaimResource($claim))->resolve($request),
            $rawItems,
        );

        return ApiResponse::success([
            'items' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'Reward claim history retrieved.');
    }

    public function vipProgress(Request $request): JsonResponse
    {
        /** @var User $member */
        $member = $request->user();

        return ApiResponse::success(
            $this->vipProgress->progressFor($member),
            'VIP progress retrieved.',
        );
    }
}
