<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\DashboardSummaryResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Home\PlayerDashboardSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DashboardSummaryController
{
    public function __construct(
        private readonly PlayerDashboardSummaryService $dashboard,
    ) {}

    public function show(Request $request): JsonResponse
    {
        /** @var User $member */
        $member = $request->user();
        $payload = (new DashboardSummaryResource($this->dashboard->summaryFor($member)))->resolve($request);

        return ApiResponse::success(
            $payload,
            'Player dashboard summary retrieved.',
        );
    }
}
