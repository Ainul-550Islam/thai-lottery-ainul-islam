<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ProfileOverviewResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Account\ProfileOverviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /me/profile/overview — the member's own profile, masked.
 *
 * Masking is applied by the service, not here and not in the client, so the
 * response is safe to log, screenshot and cache. There is no unmasked variant of
 * this endpoint: changing an email or a phone number goes through the verification
 * flow, which asks for the value again.
 */
final class ProfileOverviewController
{
    public function __construct(
        private readonly ProfileOverviewService $profiles,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // The service already masked the email and the phone. The resource never
        // receives an unmasked value, which is the point: this layer is the most
        // likely place for a "just for debugging" full address to appear.
        return ApiResponse::success(
            (new ProfileOverviewResource($this->profiles->overview($user)))->resolve(),
            'Profile loaded.',
        );
    }
}
