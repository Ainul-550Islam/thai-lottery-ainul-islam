<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ActivePromotionResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Promotions\ActivePromotionsQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /me/promotions/active — what is actually running, right now.
 *
 * A campaign that is not configured does not appear. A campaign whose window has
 * not opened does not appear. There is no "coming soon" invented from a null date
 * and no placeholder offer.
 */
final class ActivePromotionsController
{
    public function __construct(
        private readonly ActivePromotionsQueryService $promotions,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $payload = $this->promotions->active($user);

        /*
         * Only the campaign LIST is reshaped; the envelope's other keys
         * (bonuses, bonuses_available, effective_multiplier, generated_at) pass
         * through unchanged, because they are already honest statements about the
         * data rather than per-row projections.
         */
        $payload['points_campaigns'] = ActivePromotionResource::collection(
            (array) ($payload['points_campaigns'] ?? [])
        )->resolve();

        return ApiResponse::success($payload, 'Active promotions loaded.');
    }
}
