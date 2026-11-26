<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\UpdateNotificationPreferencesRequest;
use App\Http\Resources\NotificationPreferenceResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Notifications\NotificationPreferenceGuardService;
use App\Services\Notifications\NotificationPreferenceRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET/PUT /me/notification-preferences
 *
 * DIFFERENT FROM THE EXISTING PUT /api/v1/notifications/preferences ON PURPOSE
 * The existing endpoint writes ONE (event, channel) pair and is consumed by the
 * settings screen. This one returns the whole CATEGORY matrix with defaults filled
 * in and accepts a batch, which is what the notifications page renders. Both go
 * through the same underlying service; this one adds the category view and the
 * mandatory-category refusal with an explicit reason.
 */
final class NotificationPreferenceController
{
    public function __construct(
        private readonly NotificationPreferenceGuardService $guard,
    ) {
    }

    /**
     * GET /me/notification-preferences
     */
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $matrix = $this->guard->matrixFor((int) $user->id);

        return ApiResponse::success(
            [
                'preferences' => NotificationPreferenceResource::collection($matrix)->resolve(),
                'mandatory_categories' => array_values((array) config('player_experience.notifications.mandatory_categories', [])),
                'channels' => array_values((array) config('player_experience.notifications.channels', [])),
            ],
            'Preferences loaded.',
        );
    }

    /**
     * PUT /me/notification-preferences
     *
     * A refusal is 422 with a stable reason code (`mandatory_category`), not a
     * silent no-op: a toggle that appears to work and does nothing is worse than
     * one that explains itself.
     */
    public function update(UpdateNotificationPreferencesRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /*
         * VALIDATION CHECKS SHAPE; THE GUARD CHECKS LAW.
         *
         * The form request refuses an unknown event type, an unknown channel and a
         * nested object where a boolean belongs. It cannot decide whether a
         * category is mandatory - that is policy, it lives in the guard, and the
         * guard answers with a reason the screen can display.
         */
        $validated = $request->validated();

        try {
            $matrix = $this->guard->apply((int) $user->id, (array) $validated['preferences']);
        } catch (NotificationPreferenceRefused $e) {
            return ApiResponse::error($e->reasonCode, $e->getMessage(), $e->status);
        }

        return ApiResponse::success(
            ['preferences' => NotificationPreferenceResource::collection($matrix)->resolve()],
            'Preferences saved.',
        );
    }
}
