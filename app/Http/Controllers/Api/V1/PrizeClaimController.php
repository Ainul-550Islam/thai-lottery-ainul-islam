<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\PrizeClaimException;
use App\Http\Requests\Prize\CreatePrizeClaimRequest;
use App\Http\Resources\PrizeClaimResource;
use App\Http\Responses\ApiResponse;
use App\Models\Bet;
use App\Models\Payout;
use App\Services\Prize\PrizeClaimGuard;
use App\Services\Prize\PrizeClaimService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PrizeClaimController
{
    public function __construct(
        private readonly PrizeClaimService $claims,
        private readonly PrizeClaimGuard $guard,
    ) {
    }

    public function store(CreatePrizeClaimRequest $request): JsonResponse
    {
        $user = $request->user();
        $betId = $request->betId();

        $betIsTheirs = Bet::query()
            ->whereKey($betId)
            ->where('user_id', (int) $user->getAuthIdentifier())
            ->exists();

        if (! $betIsTheirs) {
            return ApiResponse::error(
                code: 'prize_claim_not_found',
                message: 'No claimable win was found under that reference.',
                status: 404,
            )->header('Cache-Control', 'private, no-store');
        }

        try {
            $result = $this->claims->claim($betId, (int) $user->getAuthIdentifier());
        } catch (PrizeClaimException) {
            return ApiResponse::error(
                code: 'prize_claim_refused',
                message: 'Prize claim could not be completed for that reference.',
                status: 422,
            )->header('Cache-Control', 'private, no-store');
        }

        return ApiResponse::success(
            data: ['claim' => (new PrizeClaimResource($result))->resolve($request)],
            message: ($result['no_op'] ?? false) === true
                ? 'This claim was already on file; returning the existing claim.'
                : 'Prize claim submitted successfully.',
            status: ($result['no_op'] ?? false) === true ? 200 : 201,
        )->header('Cache-Control', 'private, no-store');
    }

    public function show(string $bet, Request $request): JsonResponse
    {
        $user = $request->user();

        if (! ctype_digit($bet) || (int) $bet < 1) {
            return ApiResponse::error(
                code: 'prize_claim_not_found',
                message: 'No claimable win was found under that reference.',
                status: 404,
            )->header('Cache-Control', 'private, no-store');
        }

        $ownBet = Bet::query()
            ->whereKey((int) $bet)
            ->where('user_id', (int) $user->getAuthIdentifier())
            ->first();

        if (! $ownBet instanceof Bet) {
            return ApiResponse::error(
                code: 'prize_claim_not_found',
                message: 'No claimable win was found under that reference.',
                status: 404,
            )->header('Cache-Control', 'private, no-store');
        }

        $payout = $this->guard->resolve($ownBet);

        if (! $payout instanceof Payout || (int) $payout->user_id !== (int) $user->getAuthIdentifier()) {
            return ApiResponse::error(
                code: 'prize_claim_not_found',
                message: 'No claimable win was found under that reference.',
                status: 404,
            )->header('Cache-Control', 'private, no-store');
        }

        $claim = $this->claims->claimFor((int) $payout->getKey());

        if (! is_array($claim) || (int) ($claim['claimant_user_id'] ?? 0) !== (int) $user->getAuthIdentifier()) {
            return ApiResponse::error(
                code: 'prize_claim_not_found',
                message: 'No claim has been submitted for this win yet.',
                status: 404,
            )->header('Cache-Control', 'private, no-store');
        }

        return ApiResponse::success(
            data: ['claim' => (new PrizeClaimResource($claim))->resolve($request)],
            message: 'Prize claim retrieved successfully.',
        )->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $userId = (int) $user->getAuthIdentifier();
        $perPage = max(1, min(50, (int) $request->query('per_page', 15)));

        $payouts = Payout::query()
            ->where('user_id', $userId)
            ->whereNotNull('metadata->claim')
            ->latest('id')
            ->paginate($perPage);

        $items = [];
        foreach ($payouts->items() as $payout) {
            $claim = $this->claims->claimFor((int) $payout->getKey());
            if (is_array($claim) && (int) ($claim['claimant_user_id'] ?? 0) === $userId) {
                $items[] = (new PrizeClaimResource($claim))->resolve($request);
            }
        }

        return ApiResponse::success(
            data: [
                'items' => $items,
                'pagination' => [
                    'current_page' => $payouts->currentPage(),
                    'last_page' => $payouts->lastPage(),
                    'per_page' => $payouts->perPage(),
                    'total' => count($items),
                ],
            ],
            message: 'Prize claims retrieved successfully.',
        )->header('Cache-Control', 'private, no-store');
    }
}
