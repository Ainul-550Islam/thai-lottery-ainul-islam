<?php

declare(strict_types=1);

namespace App\Http\Controllers\Player;

use App\DTOs\ResponsibleGaming\SelfExclusionData;
use App\Enums\NotificationChannel;
use App\Enums\NotificationEventType;
use App\Enums\UserSessionStatus;
use App\Http\Controllers\Controller;
use App\Models\ResponsibleGamingLimit;
use App\Models\SecuritySession;
use App\Services\Account\AccountVerificationService;
use App\Services\Compliance\SelfExclusionService;
use App\Services\Security\ResponsibleGamingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

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
    ) {}

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
            /*
            | The notification matrix the page renders is the SERVER's closed
            | vocabulary, not a list typed into the view. The page prints it, the
            | endpoint validates against it, and both read the same enums, so a
            | preference row can never be offered for an event the writer refuses.
            */
            'notificationMatrix' => self::notificationMatrix(),
        ]);
    }

    /**
     * The closed notification vocabulary, as the player security page prints it.
     *
     * EVENTS are every domain event a player can hold a preference for. CHANNELS
     * are the deliverable lanes only: `none` exists in the enum to mean
     * "silenced" and is never offered as a column. MANDATORY is the floor the
     * writer refuses to disable, so the page can show those rows as locked
     * before the player tries and is told no.
     *
     * @return array{events: list<string>, channels: list<string>, mandatory: list<string>}
     */
    public static function notificationMatrix(): array
    {
        $events = array_values(array_map(
            static fn (NotificationEventType $type): string => $type->value,
            NotificationEventType::cases(),
        ));

        $channels = array_values(array_map(
            static fn (NotificationChannel $channel): string => $channel->value,
            array_values(array_filter(
                NotificationChannel::cases(),
                static fn (NotificationChannel $channel): bool => $channel->isDeliverable(),
            )),
        ));

        $mandatory = array_values(array_filter(
            $events,
            static fn (string $event): bool => NotificationEventType::from($event)->isMandatory(),
        ));

        return [
            'events' => $events,
            'channels' => $channels,
            'mandatory' => $mandatory,
        ];
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

        // Derive the horizon from the validated key rather than restating it.
        // The key already carries the number, so a lookup table duplicated it
        // and allowed the two to disagree. '24h' is the one horizon shorter
        // than a day and resolves to a single day of exclusion.
        $duration = (string) $validated['duration'];
        $days = $duration === '24h' ? 1 : (int) rtrim($duration, 'd');
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
