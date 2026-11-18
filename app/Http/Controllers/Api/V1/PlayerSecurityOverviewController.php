<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\SecurityAlertResource;
use App\Http\Resources\SecurityOverviewResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Security\SecurityAlertFeedService;
use App\Services\Security\SecurityOverviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The security score, its checks, and the alert feed. READ-ONLY.
 *
 * NAMED "PlayerSecurityOverviewController" TO AVOID A REAL COLLISION
 * App\Http\Controllers\Api\V1\Admin\Security\SecurityOverviewController already
 * exists and is already imported by routes/api.php. Two classes of the same short
 * name in one route file is a fatal error, so this one is named for the audience it
 * serves. Renaming the admin one would have been an edit to existing code.
 *
 * Neither endpoint changes a security setting. Changing 2FA, revoking a session or
 * trusting a device keeps its existing endpoint and its existing service, because
 * those carry their own re-authentication requirements (a write here would be a way
 * around them).
 */
final class PlayerSecurityOverviewController
{
    public function __construct(
        private readonly SecurityOverviewService $overview,
        private readonly SecurityAlertFeedService $alerts,
    ) {
    }

    /**
     * GET /me/security/overview
     */
    public function overview(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // The score, the maximum and the percentage all arrive from the service,
        // computed from the same booleans listed in `checks`. The resource
        // deliberately does no arithmetic, so it cannot disagree with its own list.
        return ApiResponse::success(
            ['security' => (new SecurityOverviewResource($this->overview->overview($user)))->resolve()],
            'Security overview loaded.',
        );
    }

    /**
     * GET /me/security/alerts
     */
    public function alerts(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // Each alert is already masked by the service (ip_hint, not the address).
        return ApiResponse::success(
            ['alerts' => SecurityAlertResource::collection(
                $this->alerts->alerts($user, (int) ($validated['limit'] ?? 20))
            )->resolve()],
            'Alerts loaded.',
        );
    }
}
