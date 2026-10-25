<?php

declare(strict_types=1);

namespace App\Listeners\Notifications\Concerns;

use App\DTOs\Notification\NotificationMessageData;
use App\Enums\NotificationChannel;
use App\Enums\NotificationEventType;
use App\Enums\NotificationFailureReason;
use App\Enums\NotificationPriority;
use App\Enums\QueueName;
use App\Enums\UserStatus;
use App\Exceptions\NotificationDeliveryException;
use App\Models\Notification as NotificationRow;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\Channels\SmsChannel;
use App\Services\Notification\NotificationDeliveryService;
use App\Services\Notification\NotificationDispatchService;
use App\Services\Notification\NotificationPreferenceService;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one delivery discipline shared by the four player-notification listeners.
 *
 * CANONICAL PATH. Two notification mechanisms coexist in this codebase: the framework's
 * Notification classes (mail/SMS rendering) and App\Services\Notification\* (the
 * fingerprinted `notifications` row that honours preferences, quiet hours, duplicates and
 * delivery attempts). The row is canonical. For every channel the listener first PRONOUNCES a
 * row through NotificationDispatchService - which is where a preference, a quiet window or an
 * identical earlier message stops it - and only when a fresh row came back does it send the
 * framework notification for that single channel, synchronously, inside this already-queued
 * job, and seat the outcome on the row through NotificationDeliveryService. Nothing is sent
 * twice: a replayed event meets the dedupe claim first and the row fingerprint second.
 *
 * OWNER ONLY. Every method here takes the User the event names; a listener never derives a
 * recipient from anything but the event's own owner id, and refuses when they disagree.
 */
trait DeliversPlayerNotifications
{
    public int $tries = 3;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function viaQueue(): string
    {
        return QueueName::Notifications->value;
    }

    /**
     * Claim the right to act on (event, row, status) once per dedupe window. False means
     * another worker - or an earlier run of this one - already acted.
     */
    protected function claim(string $key): bool
    {
        $ttl = max(60, (int) config('realtime.dedupe_ttl_seconds', 86400));

        try {
            return Cache::add('realtime:notify:'.$key, now()->getTimestamp(), $ttl);
        } catch (Throwable $e) {
            // A cache outage must not silence a player; the row fingerprint is the second guard.
            Log::warning('notifications.dedupe_unavailable', ['reason' => $e::class]);

            return true;
        }
    }

    protected function unclaim(string $key): void
    {
        try {
            Cache::forget('realtime:notify:'.$key);
        } catch (Throwable) {
            // Nothing to do: the claim will lapse with its TTL.
        }
    }

    /**
     * The owner row for an id, or null when there is nobody to notify.
     */
    /**
     * The player a row belongs to, or null when nobody should be told. A deleted account
     * and a banned one get nothing (there is no one to address, or no relationship left);
     * a suspended or inactive account is still told about its own money - the suspension
     * takes the socket and the API away, not the right to know a withdrawal was paid.
     */
    protected function owner(int $userId): ?User
    {
        if ($userId <= 0) {
            return null;
        }

        $user = User::query()->find($userId);

        if (! $user instanceof User) {
            return null;
        }

        return $user->status === UserStatus::Banned ? null : $user;
    }

    /**
     * The locale the player reads in: an explicit per-user locale when the schema carries one,
     * otherwise the application default, always one the lang/ directory actually has.
     */
    protected function localeFor(User $user): string
    {
        $candidate = null;

        foreach (['locale', 'preferred_locale', 'language'] as $attribute) {
            $value = $user->getAttribute($attribute);

            if (is_string($value) && trim($value) !== '') {
                $candidate = strtolower(substr(trim($value), 0, 2));
                break;
            }
        }

        $candidate ??= strtolower(substr((string) config('app.locale', 'en'), 0, 2));

        return is_dir(lang_path($candidate)) ? $candidate : strtolower(substr((string) config('app.fallback_locale', 'en'), 0, 2));
    }

    /**
     * Subject and body for the notification-centre row, from lang/<locale>/notifications.php
     * under `inapp.<group>.<copy key>_subject` / `_body`.
     *
     * @param  array<string, string>  $params
     * @return array{0: string, 1: string}
     */
    protected function inAppCopy(string $group, string $copyKey, array $params, string $locale): array
    {
        $key = sprintf('notifications.inapp.%s.%s', $group, $copyKey);

        return [
            (string) __($key.'_subject', $params, $locale),
            (string) __($key.'_body', $params, $locale),
        ];
    }

    /**
     * Pronounce the in-app row. Returns the row when one was created, null when suppressed or replayed.
     */
    protected function pronounceInApp(User $user, NotificationEventType $type, string $subject, string $body, string $locale, NotificationPriority $priority = NotificationPriority::Normal): ?NotificationRow
    {
        $row = $this->pronounce($user, $type, NotificationChannel::InApp, $subject, $body, $locale, $priority);

        if ($row === null) {
            return null;
        }

        // The notification centre reads the row itself, so the row existing IS the delivery:
        // one attempt, seated delivered, and the status the player sees is honest.
        if ($this->begin($row)) {
            $this->deliveryService()->seatDelivered($row);
        }

        return $row;
    }

    /**
     * Mail lane: pronounce the Email row (preferences, quiet hours and duplicates are decided
     * there), then send the framework notification on the mail channel only and seat the outcome.
     */
    protected function deliverMail(User $user, NotificationEventType $type, string $subject, string $body, string $locale, Notification $notification, NotificationPriority $priority = NotificationPriority::Normal): void
    {
        $email = $user->getAttribute('email');

        if (! is_string($email) || trim($email) === '') {
            return;
        }

        $row = $this->pronounce($user, $type, NotificationChannel::Email, $subject, $body, $locale, $priority);

        if (! $row instanceof NotificationRow) {
            return;
        }

        if (! $this->begin($row)) {
            return;
        }

        try {
            $user->notifyNow($notification->locale($locale), ['mail']);
            $this->deliveryService()->seatDelivered($row);
        } catch (Throwable $e) {
            Log::warning('notifications.mail.send_failed', [
                'notification_id' => (int) $row->id,
                'event_type' => $type->value,
                'reason' => $e::class,
            ]);
            $this->seatFailure($row, NotificationFailureReason::ProviderFailure);
        }
    }

    /**
     * SMS lane: explicit opt-in for (event, sms) AND a verified number, then the row, then
     * SmsChannel - which never throws - and the outcome seated on the row.
     */
    protected function deliverSms(User $user, NotificationEventType $type, string $subject, string $body, string $locale, Notification $notification, NotificationPriority $priority = NotificationPriority::Normal): void
    {
        if (! $this->smsOptedIn((int) $user->getKey(), $type)) {
            return;
        }

        $channel = $this->smsChannel();

        if ($channel->destinationFor($user) === null) {
            return;
        }

        $row = $this->pronounce($user, $type, NotificationChannel::Sms, $subject, $body, $locale, $priority);

        if (! $row instanceof NotificationRow) {
            return;
        }

        if (! $this->begin($row)) {
            return;
        }

        $outcome = $channel->deliver($user, $notification->locale($locale));

        match ($outcome) {
            SmsChannel::SENT => $this->deliveryService()->seatDelivered($row),
            SmsChannel::SKIPPED => $this->seatFailure($row, NotificationFailureReason::InvalidDestination),
            default => $this->seatFailure($row, NotificationFailureReason::ProviderFailure),
        };
    }

    /**
     * SMS is opt-IN: an explicit, enabled preference row for (user, event, sms). The absence of
     * a row - which the preference service reads as "enabled" for the in-app/mail floor - is
     * not consent to be texted.
     */
    protected function smsOptedIn(int $userId, NotificationEventType $type): bool
    {
        if (! $this->preferenceService()->enabledFor($userId, $type, NotificationChannel::Sms)) {
            return false;
        }

        return NotificationPreference::query()
            ->where('user_id', $userId)
            ->where('event_type', $type->value)
            ->where('channel', NotificationChannel::Sms->value)
            ->where('enabled', true)
            ->exists();
    }

    private function pronounce(User $user, NotificationEventType $type, NotificationChannel $channel, string $subject, string $body, string $locale, NotificationPriority $priority): ?NotificationRow
    {
        $message = NotificationMessageData::fromInput([
            'user_id' => (int) $user->getKey(),
            'event_type' => $type,
            'channel' => $channel,
            'priority' => $priority,
            'locale' => $locale,
            'subject' => $subject,
            'body' => $body,
        ]);

        $result = $this->dispatchService()->pronounce((int) $user->getKey(), $message);

        if ($result['suppressed'] === true || $result['replayed'] === true) {
            return null;
        }

        return $result['notification'] instanceof NotificationRow ? $result['notification'] : null;
    }

    private function begin(NotificationRow $row): bool
    {
        try {
            $attempt = $this->deliveryService()->beginAttempt($row);
        } catch (NotificationDeliveryException) {
            return false; // terminal or past horizon: the row carries the truth
        }

        return $attempt['replayed'] === false;
    }

    private function seatFailure(NotificationRow $row, NotificationFailureReason $reason): void
    {
        try {
            $this->deliveryService()->seatFailed($row, $reason);
        } catch (Throwable $e) {
            Log::error('notifications.attempt.seat_failed', [
                'notification_id' => (int) $row->id,
                'reason' => $e::class,
            ]);
        }
    }

    private function dispatchService(): NotificationDispatchService
    {
        return app(NotificationDispatchService::class);
    }

    private function deliveryService(): NotificationDeliveryService
    {
        return app(NotificationDeliveryService::class);
    }

    private function preferenceService(): NotificationPreferenceService
    {
        return app(NotificationPreferenceService::class);
    }

    private function smsChannel(): SmsChannel
    {
        return app(SmsChannel::class);
    }
}
