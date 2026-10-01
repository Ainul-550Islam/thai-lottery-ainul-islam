<?php

declare(strict_types=1);

namespace App\Http\Controllers\Player;

use App\DTOs\ResponsibleGaming\SelfExclusionData;
use App\Http\Controllers\Controller;
use App\Models\ResponsibleGamingLimit;
use App\Models\SecuritySession;
use App\Enums\UserSessionStatus;
use App\Services\Account\AccountVerificationService;
use App\Services\Compliance\SelfExclusionService;
use App\Services\Security\ResponsibleGamingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Authenticated password, verification and player-protection page adapter.
 *
 * Every displayed state is derived from the current session user and the
 * existing responsible-gaming/security records. No identity, KYC, balance,
 * session or limit fixture is used.
 */
final class PlayerSecuritySettingsController extends Controller
{
    public function __construct(
        private readonly ResponsibleGamingService $responsibleGaming,
        private readonly SelfExclusionService $selfExclusions,
        private readonly AccountVerificationService $verification,
    ) {
    }

    public function index(Request $request): View
    {
        $user = Auth::user();
        $limits = ResponsibleGamingLimit::query()->where('user_id', $user->id)->first();
        $activeExclusion = $this->selfExclusions->currentActiveFor((int) $user->id);
        $sessions = SecuritySession::query()
            ->where('user_id', $user->id)
            ->where('status', UserSessionStatus::Active)
            ->where('expires_at', '>', now())
            ->orderByDesc('last_seen_at')
            ->limit(20)
            ->get();

        return view('player.security', [
            'user' => $user,
            'limits' => $limits,
            'activeExclusion' => $activeExclusion,
            'sessions' => $sessions,
            'kycStatus' => $this->verification->publicStatus($user),
        ]);
    }

    public function getLimitsApi(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return $this->authenticationRequired();
        }

        $limits = ResponsibleGamingLimit::query()->where('user_id', $user->id)->first();
        $exclusion = $this->selfExclusions->currentActiveFor((int) $user->id);

        return response()->json([
            'status' => 'success',
            'kyc_status' => $this->verification->publicStatus($user),
            'limits' => [
                'daily_deposit' => $limits?->daily_deposit_limit,
                'single_bet' => $limits?->single_bet_limit,
                'daily_wagering' => $limits?->daily_wagering_limit,
            ],
            'self_exclusion' => $exclusion === null ? null : [
                'status' => $exclusion->status->value,
                'ends_at' => $exclusion->ends_at?->toIso8601String(),
            ],
        ]);
    }

    public function updateLimitsApi(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return $this->authenticationRequired();
        }

        $validated = $request->validate([
            'dailyDeposit' => ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'singleBet' => ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'dailyWager' => ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
        ]);

        $record = $this->responsibleGaming->setLimits(
            user: $user,
            dailyDepositLimit: $validated['dailyDeposit'] ?? null,
            singleBetLimit: $validated['singleBet'] ?? null,
            dailyWageringLimit: $validated['dailyWager'] ?? null,
        );

        return response()->json([
            'status' => 'success',
            'message' => (string) trans('player.security_limits_updated'),
            'limits' => [
                'daily_deposit' => $record->daily_deposit_limit,
                'single_bet' => $record->single_bet_limit,
                'daily_wagering' => $record->daily_wagering_limit,
            ],
        ]);
    }

    public function setSelfExclusionApi(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return $this->authenticationRequired();
        }

        $validated = $request->validate([
            'duration' => ['required', 'string', Rule::in(['24h', '7d', '30d', '90d', '365d'])],
        ]);

        $days = [
            '24h' => 1,
            '7d' => 7,
            '30d' => 30,
            '90d' => 90,
            '365d' => 365,
        ][(string) $validated['duration']];
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
            'status' => 'success',
            'message' => (string) trans('player.security_self_exclusion_activated'),
            'active_until' => $record->ends_at?->toIso8601String(),
        ]);
    }

    private function authenticationRequired(): JsonResponse
    {
        return response()->json([
            'status' => 'AUTHENTICATION_REQUIRED',
            'message' => (string) trans('player.auth_required_security'),
        ], 401);
    }
}
