<?php

declare(strict_types=1);

namespace App\Http\Controllers\Player;

use App\DTOs\ResponsibleGaming\SelfExclusionData;
use App\Http\Controllers\Controller;
use App\Models\ResponsibleGamingLimit;
use App\Services\Compliance\SelfExclusionService;
use App\Services\Security\ResponsibleGamingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Compatibility adapter for the retired all-in-one settings portal.
 *
 * Only user preferences already supported by the User model and the canonical
 * responsible-gaming service are exposed. MFA, notification, LINE, PIN and
 * betting-preference mutations fail closed until their existing backend
 * contracts are explicitly wired; this adapter never returns presentation-only
 * success or secret material.
 */
final class PlayerSettingsPortalController extends Controller
{
    public function __construct(
        private readonly SelfExclusionService $selfExclusions,
    ) {}

    public function index(Request $request): RedirectResponse
    {
        return $request->user() !== null
            ? redirect()->route('settings.index')
            : redirect()->route('login');
    }

    public function getAllSettingsApi(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return $this->authenticationRequired();
        }

        $limits = ResponsibleGamingLimit::query()->where('user_id', $user->id)->first();

        return response()->json([
            'success' => true,
            'data' => [
                'preferences' => $user->preferences ?? [],
                'responsible_gaming' => [
                    'daily_deposit_limit' => $limits?->daily_deposit_limit,
                    'single_bet_limit' => $limits?->single_bet_limit,
                    'daily_wagering_limit' => $limits?->daily_wagering_limit,
                    'self_excluded_until' => $limits?->self_excluded_until?->toIso8601String(),
                ],
            ],
        ]);
    }

    public function updateGeneralSettingsApi(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return $this->authenticationRequired();
        }

        $validated = $request->validate([
            'language' => ['sometimes', 'string', Rule::in(['en', 'th'])],
            'theme' => ['sometimes', 'string', 'max:32'],
            'timezone' => ['sometimes', 'timezone'],
            'currency_format' => ['sometimes', 'string', 'size:3'],
        ]);
        $preferences = is_array($user->preferences) ? $user->preferences : [];
        $user->preferences = array_merge($preferences, $validated);
        $user->save();

        return response()->json([
            'success' => true,
            'message' => (string) trans('player.general_preferences_updated'),
            'data' => $user->preferences,
        ]);
    }

    public function updateSecuritySettingsApi(Request $request): JsonResponse
    {
        return $this->notConfigured((string) trans('player.not_configured_security_persistence'));
    }

    public function toggle2faApi(Request $request): JsonResponse
    {
        return $this->notConfigured((string) trans('player.not_configured_mfa'));
    }

    public function updateBettingPreferencesApi(Request $request): JsonResponse
    {
        return $this->notConfigured((string) trans('player.not_configured_betting_preferences'));
    }

    public function updateNotificationPreferencesApi(Request $request): JsonResponse
    {
        return $this->notConfigured((string) trans('player.not_configured_notification_preferences'));
    }

    public function bindLineNotifyApi(Request $request): JsonResponse
    {
        return $this->notConfigured((string) trans('player.not_configured_line'));
    }

    public function updateResponsibleGamingLimitsApi(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return $this->authenticationRequired();
        }

        $validated = $request->validate([
            'daily_deposit_limit' => ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'single_bet_limit' => ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'daily_wager_limit' => ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
        ]);

        $record = app(ResponsibleGamingService::class)->setLimits(
            user: $user,
            dailyDepositLimit: $validated['daily_deposit_limit'] ?? null,
            singleBetLimit: $validated['single_bet_limit'] ?? null,
            dailyWageringLimit: $validated['daily_wager_limit'] ?? null,
        );

        return response()->json([
            'success' => true,
            'message' => (string) trans('player.responsible_limits_updated'),
            'data' => [
                'daily_deposit_limit' => $record->daily_deposit_limit,
                'single_bet_limit' => $record->single_bet_limit,
                'daily_wagering_limit' => $record->daily_wagering_limit,
            ],
        ]);
    }

    public function applySelfExclusionApi(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return $this->authenticationRequired();
        }

        $validated = $request->validate([
            'duration' => ['required', 'string', Rule::in(['24h', '7d', '30d', '365d'])],
        ]);
        $days = ['24h' => 1, '7d' => 7, '30d' => 30, '365d' => 365][(string) $validated['duration']];
        $requestData = SelfExclusionData::fromInput([
            'user_id' => (int) $user->id,
            'effective_at' => now(),
            'ends_at' => now()->addDays($days),
            'scope' => 'account',
            'reason_code' => 'PLAYER_REQUESTED',
        ]);
        $requested = $this->selfExclusions->request($requestData);
        $record = $this->selfExclusions->activate($requested);

        return response()->json([
            'success' => true,
            'message' => (string) trans('player.self_exclusion_activated'),
            'duration_ends_at' => $record->ends_at?->toIso8601String(),
        ]);
    }

    private function notConfigured(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'status' => 'NOT_CONFIGURED',
            'message' => $message,
        ], 503);
    }

    private function authenticationRequired(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'status' => 'AUTHENTICATION_REQUIRED',
            'message' => (string) trans('player.auth_required_settings'),
        ], 401);
    }
}
