<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\DTOs\Notification\NotificationPreferenceData;
use App\Enums\NotificationChannel;
use App\Enums\NotificationEventType;
use App\Models\NotificationPreference;
use App\Services\Notification\NotificationPreferenceService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Batch-3 face of notification preferences: categories, channels, and the
 * mandatory floor. Every row write DELEGATES to the existing service.
 *
 * WHY THIS CLASS EXISTS AT ALL
 * App\Services\Notification\NotificationPreferenceService is keyed by
 * (event_type, channel) and already refuses to silence a mandatory event. Batch 3
 * needs two things it does not offer: a CATEGORY view (the screen groups events
 * into security / deposit_status / withdrawal_status / kyc / responsible_gaming /
 * account_restriction / marketing / ...) and a way to say WHY a row cannot be
 * changed. Both live here; the table still has exactly one writer.
 *
 * THE MANDATORY FLOOR IS ENFORCED TWICE, ON PURPOSE
 *   1. NotificationEventType::isMandatory() — the existing statutory floor
 *   2. config('player_experience.notifications.mandatory_categories') — the
 *      operator-declared categories
 * A row that either says is mandatory is refused with 422 `mandatory_category`,
 * and the stored row is stamped locked_by_policy so the member's screen can show
 * the lock instead of a toggle that bounces.
 *
 * AN UNCLASSIFIED EVENT LANDS IN 'other'
 * Defaulting an unknown event to a mandatory category would make it
 * unsilenceable by accident; defaulting it to a silenceable one could drop a
 * notice nobody meant to lose. 'other' is optional, and it is visible in the
 * payload, so the ambiguity is reported instead of decided.
 */
final class NotificationPreferenceGuardService
{
    public function __construct(
        private readonly NotificationPreferenceService $preferences,
    ) {
    }

    public function categoryFor(NotificationEventType $eventType): string
    {
        $value = $eventType->value;

        // A category named as a prefix of the event wins ('security.*').
        foreach ((array) config('player_experience.notifications.mandatory_categories', []) as $category) {
            if ($category !== '' && str_starts_with($value, (string) $category)) {
                return (string) $category;
            }
        }

        foreach ((array) config('player_experience.notifications.optional_categories', []) as $category) {
            if ($category !== '' && str_starts_with($value, (string) $category)) {
                return (string) $category;
            }
        }

        return match ($value) {
            'kyc_verified', 'kyc_rejected' => 'kyc',
            'deposit_confirmed', 'deposit_failed' => 'deposit_status',
            'withdrawal_approved', 'withdrawal_paid', 'withdrawal_rejected' => 'withdrawal_status',
            'compliance_hold', 'compliance_released' => 'account_restriction',
            'self_exclusion_started', 'limit_changed', 'reality_check' => 'responsible_gaming',
            'session_revoked' => 'security',
            default => 'other',
        };
    }

    public function isMandatoryCategory(string $category): bool
    {
        return in_array($category, (array) config('player_experience.notifications.mandatory_categories', []), true);
    }

    /**
     * The full matrix for a member, with defaults filled in and nothing written.
     *
     * A member who has never opened the screen has no rows, and the screen still
     * has to show the truth ("everything on, security locked"). Defaults are
     * computed here, so merely viewing preferences never mutates the database.
     *
     * @return list<array<string, mixed>>
     */
    public function matrixFor(int $userId): array
    {
        /** @var Collection<int, NotificationPreference> $rows */
        $rows = $this->preferences->allFor($userId);

        $existing = [];

        foreach ($rows as $row) {
            $existing[$row->event_type->value.'|'.$row->channel->value] = $row;
        }

        $out = [];

        foreach (NotificationEventType::cases() as $eventType) {
            $category = $this->categoryFor($eventType);
            $mandatory = $eventType->isMandatory() || $this->isMandatoryCategory($category);

            foreach ((array) config('player_experience.notifications.channels', ['in_app']) as $channelValue) {
                $channel = NotificationChannel::tryFrom((string) $channelValue);

                if (! $channel instanceof NotificationChannel || ! $channel->isDeliverable()) {
                    continue;
                }

                $row = $existing[$eventType->value.'|'.$channel->value] ?? null;

                $out[] = [
                    'event_type' => $eventType->value,
                    'label' => $eventType->label(),
                    'category' => $category,
                    'channel' => $channel->value,
                    'enabled' => $row instanceof NotificationPreference ? (bool) $row->enabled : true,
                    'mandatory' => $mandatory,
                    'locked' => $mandatory,
                    'reason' => $mandatory ? 'mandatory_category' : null,
                    'quiet_hours' => $row?->quiet_hours,
                ];
            }
        }

        return $out;
    }

    /**
     * Apply a member's changes, refusing to silence a mandatory category.
     *
     * Validation happens for EVERY row before ANY write, so a bad row at the end
     * of the list cannot leave the first half applied.
     *
     * @param  list<array{event_type:string, channel:string, enabled:bool, quiet_hours?:array<string,string>|null}>  $changes
     * @return list<array<string, mixed>>
     *
     * @throws NotificationPreferenceRefused
     */
    public function apply(int $userId, array $changes): array
    {
        $resolved = [];

        foreach ($changes as $change) {
            $eventType = NotificationEventType::tryFrom(strtolower(trim((string) ($change['event_type'] ?? ''))));

            if (! $eventType instanceof NotificationEventType) {
                throw new NotificationPreferenceRefused(
                    'unknown_event_type',
                    sprintf('"%s" is not a notification event type.', (string) ($change['event_type'] ?? '')),
                );
            }

            $channel = NotificationChannel::tryFrom(strtolower(trim((string) ($change['channel'] ?? ''))));

            if (! $channel instanceof NotificationChannel || ! $channel->isDeliverable()) {
                throw new NotificationPreferenceRefused(
                    'unknown_channel',
                    sprintf('"%s" is not a delivery channel.', (string) ($change['channel'] ?? '')),
                );
            }

            $category = $this->categoryFor($eventType);
            $mandatory = $eventType->isMandatory() || $this->isMandatoryCategory($category);
            $enabled = (bool) ($change['enabled'] ?? true);

            if ($mandatory && ! $enabled) {
                throw new NotificationPreferenceRefused(
                    'mandatory_category',
                    sprintf('%s cannot be switched off: it is a mandatory notice.', $eventType->label()),
                );
            }

            $resolved[] = [
                'event_type' => $eventType,
                'channel' => $channel,
                'enabled' => $enabled,
                'mandatory' => $mandatory,
                'quiet_hours' => $change['quiet_hours'] ?? null,
            ];
        }

        DB::transaction(function () use ($userId, $resolved): void {
            foreach ($resolved as $row) {
                $this->preferences->write(NotificationPreferenceData::fromInput([
                    'user_id' => $userId,
                    'event_type' => $row['event_type']->value,
                    'channel' => $row['channel']->value,
                    'enabled' => $row['enabled'],
                    'quiet_hours' => $row['quiet_hours'],
                ]));

                NotificationPreference::query()
                    ->where('user_id', $userId)
                    ->where('event_type', $row['event_type']->value)
                    ->where('channel', $row['channel']->value)
                    ->update(['locked_by_policy' => $row['mandatory']]);
            }
        });

        return $this->matrixFor($userId);
    }

    /**
     * Whether a non-mandatory category may be sent to this member.
     *
     * This is the method the dispatcher consults (see the EDIT section): true when
     * the category is mandatory, otherwise the stored preference, defaulting to
     * enabled.
     */
    public function allows(int $userId, NotificationEventType $eventType, NotificationChannel $channel): bool
    {
        if ($eventType->isMandatory() || $this->isMandatoryCategory($this->categoryFor($eventType))) {
            return true;
        }

        return $this->preferences->enabledFor($userId, $eventType, $channel);
    }
}
