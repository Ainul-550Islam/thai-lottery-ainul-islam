<?php

declare(strict_types=1);

namespace App\Http\Controllers\Player;

use App\DTOs\Notification\NotificationPreferenceData;
use App\DTOs\ResponsibleGaming\SelfExclusionData;
use App\Enums\AuditAction;
use App\Enums\NotificationChannel;
use App\Enums\NotificationEventType;
use App\Enums\RiskLevel;
use App\Http\Controllers\Controller;
use App\Models\ResponsibleGamingLimit;
use App\Services\Audit\AuditLogService;
use App\Services\Compliance\SelfExclusionService;
use App\Services\Notification\NotificationPreferenceService;
use App\Services\Security\ResponsibleGamingService;
use App\Exceptions\NotificationPreferenceException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Compatibility adapter for the retired all-in-one settings portal.
 *
 * Only user preferences already supported by the User model, the canonical
 * responsible-gaming service and the canonical notification-preference service
 * are exposed. MFA, security, LINE, PIN and betting-preference mutations fail
 * closed until their existing backend contracts are explicitly wired; this
 * adapter never returns presentation-only success or secret material.
 *
 * NOTIFICATION PREFERENCES are now a real write: the vocabulary is the closed
 * App\Enums\NotificationEventType × NotificationChannel matrix, persistence
 * goes through App\Services\Notification\NotificationPreferenceService (which
 * owns the one-row-per-(user, event, channel) rule), and the mandatory floor is
 * enforced twice — here with a readable 422, and again inside
 * NotificationPreferenceData, which refuses to build a row that silences a
 * mandatory notice.
 */
final class PlayerSettingsPortalController extends Controller
{
    /**
     * Event types that can never be switched off, in addition to the enum's own
     * mandatory floor (compliance, exclusion, session revoke, reality check).
     *
     * These are the terminal states of a withdrawal: a player who silenced the
     * outcome of their own money movement would be silenced by the platform at
     * the exact moment the answer matters most.
     *
     * @var list<NotificationEventType>
     */
    private const ALWAYS_DELIVERED_EVENTS = [
        NotificationEventType::WithdrawalPaid,
        NotificationEventType::WithdrawalRejected,
    ];

    /** Quiet-hour clock format accepted from a client. */
    private const CLOCK_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d$/';

    public function __construct(
        private readonly SelfExclusionService $selfExclusions,
        private readonly NotificationPreferenceService $notificationPreferences,
        private readonly AuditLogService $audit,
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
        $user = $request->user();

        if ($user === null) {
            return $this->authenticationRequired();
        }

        // The vocabulary is the enum, not a free-text field: an event type or a
        // channel outside the closed matrix is a client error, never a row.
        $validated = $request->validate([
            'preferences' => ['required', 'array', 'min:1', 'max:100'],
            'preferences.*.event_type' => ['required', 'string', Rule::in($this->notificationEventTypes())],
            'preferences.*.channel' => ['required', 'string', Rule::in($this->deliverableChannels())],
            'preferences.*.enabled' => ['required', 'boolean'],
            'preferences.*.quiet_hours' => ['sometimes', 'nullable', 'array'],
            'preferences.*.quiet_hours.start' => ['required_with:preferences.*.quiet_hours', 'string', 'regex:'.self::CLOCK_PATTERN],
            'preferences.*.quiet_hours.end' => ['required_with:preferences.*.quiet_hours', 'string', 'regex:'.self::CLOCK_PATTERN],
        ], $this->notificationPreferenceMessages());

        $preferences = $this->normalisedPreferences((array) $validated['preferences']);

        foreach ($preferences as $index => $preference) {
            $eventType = NotificationEventType::from((string) $preference['event_type']);

            if ($preference['enabled'] || ! $this->cannotBeDisabled($eventType)) {
                continue;
            }

            // Fail before anything is written: a partially applied matrix that
            // silenced a mandatory notice on the way would be worse than none.
            throw ValidationException::withMessages([
                'preferences.'.$index.'.enabled' => (string) trans('player.notification_preferences_mandatory'),
            ]);
        }

        try {
            $rows = DB::transaction(function () use ($user, $preferences): array {
                foreach ($preferences as $preference) {
                    $this->notificationPreferences->write(NotificationPreferenceData::fromInput([
                        'user_id' => (int) $user->id,
                        'event_type' => (string) $preference['event_type'],
                        'channel' => (string) $preference['channel'],
                        'enabled' => $preference['enabled'],
                        'quiet_hours' => $preference['quiet_hours'],
                    ]));
                }

                return $this->notificationMatrix((int) $user->id);
            });
        } catch (NotificationPreferenceException $exception) {
            // A malformed combination that reached the DTO (an undecliverable
            // channel, a silenced mandatory notice, unusable quiet hours) is a
            // client error with a safe reason - never an internal message.
            return response()->json([
                'success' => false,
                'status' => 'INVALID_PREFERENCE',
                'message' => (string) trans('player.notification_preferences_mandatory'),
            ], 422);
        }

        $this->audit->log(
            userId: (int) $user->id,
            action: AuditAction::Update,
            riskLevel: RiskLevel::Low,
            auditable: $user,
            description: 'Player notification preferences updated.',
            metadata: [
                'preferences' => array_map(
                    static fn (array $preference): array => [
                        'event_type' => (string) $preference['event_type'],
                        'channel' => (string) $preference['channel'],
                        'enabled' => $preference['enabled'],
                        'has_quiet_hours' => $preference['quiet_hours'] !== null,
                    ],
                    $preferences,
                ),
                'count' => count($preferences),
            ],
        );

        return response()->json([
            'success' => true,
            'message' => (string) trans('player.notification_preferences_updated'),
            'data' => [
                'preferences' => $rows,
            ],
        ]);
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

    /**
     * The validated matrix as a typed list.
     *
     * `enabled` is normalised here, once, so the mandatory-floor check and the
     * persisted row can never disagree about what the player asked for (a
     * client that sends 1 or "0" means the same thing as true or false).
     *
     * @param  array<int, array<string, mixed>>  $preferences
     * @return list<array{event_type: string, channel: string, enabled: bool, quiet_hours: array{start: string, end: string}|null}>
     */
    private function normalisedPreferences(array $preferences): array
    {
        $normalised = [];

        foreach (array_values($preferences) as $preference) {
            $quietHours = $preference['quiet_hours'] ?? null;

            $normalised[] = [
                'event_type' => (string) $preference['event_type'],
                'channel' => (string) $preference['channel'],
                'enabled' => filter_var($preference['enabled'], FILTER_VALIDATE_BOOLEAN),
                'quiet_hours' => is_array($quietHours) && isset($quietHours['start'], $quietHours['end'])
                    ? ['start' => (string) $quietHours['start'], 'end' => (string) $quietHours['end']]
                    : null,
            ];
        }

        return $normalised;
    }

    /**
     * Every event type a player may hold a preference for.
     *
     * @return list<string>
     */
    private function notificationEventTypes(): array
    {
        return array_values(array_map(
            static fn (NotificationEventType $type): string => $type->value,
            NotificationEventType::cases(),
        ));
    }

    /**
     * Every channel a preference can actually be delivered on. The 'none'
     * channel means "silenced" and is never a client-selectable lane.
     *
     * @return list<string>
     */
    private function deliverableChannels(): array
    {
        return array_values(array_map(
            static fn (NotificationChannel $channel): string => $channel->value,
            array_filter(
                NotificationChannel::cases(),
                static fn (NotificationChannel $channel): bool => $channel->isDeliverable(),
            ),
        ));
    }

    /**
     * The mandatory floor: the enum's own compliance/security set plus the
     * withdrawal terminal states this adapter also refuses to silence.
     */
    private function cannotBeDisabled(NotificationEventType $eventType): bool
    {
        return $eventType->isMandatory() || in_array($eventType, self::ALWAYS_DELIVERED_EVENTS, true);
    }

    /**
     * The player's saved matrix, newest vocabulary order included.
     *
     * Only the authenticated player's own rows are read; the query is scoped by
     * the authenticated id, never by a client-supplied one.
     *
     * @return list<array<string, mixed>>
     */
    private function notificationMatrix(int $userId): array
    {
        return $this->notificationPreferences->allFor($userId)
            ->map(static fn ($row): array => [
                'event_type' => $row->event_type->value,
                'channel' => $row->channel->value,
                'enabled' => (bool) $row->enabled,
                'quiet_hours' => $row->quiet_hours,
                'mandatory' => $row->event_type->isMandatory(),
            ])
            ->values()
            ->all();
    }

    /**
     * Localized validation sentences for the preference matrix.
     *
     * @return array<string, string>
     */
    private function notificationPreferenceMessages(): array
    {
        return [
            'preferences.required' => (string) trans('player.notification_preferences_required'),
            'preferences.array' => (string) trans('player.notification_preferences_required'),
            'preferences.min' => (string) trans('player.notification_preferences_required'),
            'preferences.*.event_type.required' => (string) trans('player.notification_preferences_event_invalid'),
            'preferences.*.event_type.in' => (string) trans('player.notification_preferences_event_invalid'),
            'preferences.*.channel.required' => (string) trans('player.notification_preferences_channel_invalid'),
            'preferences.*.channel.in' => (string) trans('player.notification_preferences_channel_invalid'),
            'preferences.*.enabled.required' => (string) trans('player.notification_preferences_enabled_invalid'),
            'preferences.*.enabled.boolean' => (string) trans('player.notification_preferences_enabled_invalid'),
            'preferences.*.quiet_hours.array' => (string) trans('player.notification_preferences_quiet_hours_invalid'),
            'preferences.*.quiet_hours.start.required_with' => (string) trans('player.notification_preferences_quiet_hours_invalid'),
            'preferences.*.quiet_hours.start.regex' => (string) trans('player.notification_preferences_quiet_hours_invalid'),
            'preferences.*.quiet_hours.end.required_with' => (string) trans('player.notification_preferences_quiet_hours_invalid'),
            'preferences.*.quiet_hours.end.regex' => (string) trans('player.notification_preferences_quiet_hours_invalid'),
        ];
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
