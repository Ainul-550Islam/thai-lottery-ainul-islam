<?php

declare(strict_types=1);

namespace App\Listeners\Notifications;

use App\Enums\KycStatus;
use App\Enums\NotificationEventType;
use App\Events\KycStatusChanged;
use App\Listeners\Notifications\Concerns\DeliversPlayerNotifications;
use App\Notifications\KycStatusNotification;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells the player the outcome of an identity-verification decision.
 *
 * Listens to App\Events\KycStatusChanged, the envelope EventServiceProvider raises from the
 * KYC lane's own row writes (users.kyc_status, kyc_verifications, account_verifications,
 * kyc_documents) - no KYC service was edited to make this fire.
 *
 * MEANINGFUL TRANSITIONS: verified, rejected, expired (mail + in-app) and under_review
 * (in-app only). pending/unverified are the player's own submissions.
 *
 * IDEMPOTENT ACROSS SOURCES. A review that writes the document row AND the user column raises
 * two envelopes for one decision; the claim is keyed on (user, status), not on the source row,
 * so the player hears it once.
 */
final class SendKycStatusNotification implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    use DeliversPlayerNotifications;
    use InteractsWithQueue;

    public function shouldQueue(KycStatusChanged $event): bool
    {
        return self::transitionFor($event->currentStatus) !== null
            && $event->previousStatus !== $event->currentStatus;
    }

    /**
     * Typed `object`, not KycStatusChanged, on purpose: the framework's event discovery
     * (enabled by Application::configure() in bootstrap/app.php) registers every
     * `handle(SomeEvent $e)` it finds under app/Listeners a second time next to the
     * explicit entry in EventServiceProvider::$listen, and a listener registered twice
     * queues two jobs per fact. The explicit registration is the one that counts.
     */
    public function handle(object $event): void
    {
        if (! $event instanceof KycStatusChanged) {
            return;
        }

        $transition = self::transitionFor($event->currentStatus);

        if ($transition === null || $event->previousStatus === $event->currentStatus) {
            return;
        }

        [$eventType, $copyKey, $withMail] = $transition;
        $userId = (int) $event->user->getKey();
        $claim = 'kyc:'.$userId.':'.$event->currentStatus;

        if (! $this->claim($claim)) {
            return;
        }

        try {
            $user = $this->owner($userId);

            if ($user === null) {
                return;
            }

            $locale = $this->localeFor($user);
            [$subject, $body] = $this->inAppCopy('kyc', $copyKey, [], $locale);

            $this->pronounceInApp($user, $eventType, $subject, $body, $locale);

            if ($withMail) {
                $notification = new KycStatusNotification(
                    status: $event->currentStatus,
                    reason: $event->currentStatus === KycStatus::Rejected->value ? self::playerFacingReason($event->reason) : null,
                );

                $this->deliverMail($user, $eventType, $subject, $body, $locale, $notification);
            }
        } catch (Throwable $e) {
            $this->unclaim($claim);

            throw $e;
        }
    }

    public function failed(KycStatusChanged $event, Throwable $exception): void
    {
        Log::error('notifications.kyc.listener_failed', [
            'source' => $event->sourceType,
            'source_id' => $event->sourceId,
            'reason' => $exception::class,
        ]);
    }

    /**
     * @return array{0: NotificationEventType, 1: string, 2: bool}|null event type, copy key, send mail
     */
    public static function transitionFor(string $status): ?array
    {
        return match ($status) {
            KycStatus::Verified->value => [NotificationEventType::KycVerified, 'verified', true],
            KycStatus::Rejected->value => [NotificationEventType::KycRejected, 'rejected', true],
            KycStatus::Expired->value => [NotificationEventType::KycRejected, 'expired', true],
            KycStatus::UnderReview->value => [NotificationEventType::Manual, 'under_review', false],
            default => null,
        };
    }

    private static function playerFacingReason(?string $reason): ?string
    {
        return is_string($reason) && trim($reason) !== '' ? mb_substr(trim($reason), 0, 500) : null;
    }
}
