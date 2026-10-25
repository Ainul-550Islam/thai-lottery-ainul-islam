<?php

declare(strict_types=1);

namespace App\Listeners\Notifications;

use App\Enums\NotificationEventType;
use App\Enums\WithdrawalStatus;
use App\Events\WithdrawalStatusUpdated;
use App\Listeners\Notifications\Concerns\DeliversPlayerNotifications;
use App\Models\Withdrawal;
use App\Notifications\WithdrawalStatusNotification;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells the player when their withdrawal moved to a state they act on or wait for.
 *
 * MEANINGFUL TRANSITIONS: approved, processing, completed, rejected, failed. `pending`,
 * `under_review`, `kyc_required` and `cancelled` stay quiet here - the first three are the
 * platform's own queue positions (and the KYC gate has its own lane), the last is the
 * player's own act.
 *
 * NEVER A BANK ACCOUNT NUMBER. The notification renders at most the bank name and the last
 * four digits (WithdrawalStatusNotification::destinationLabel()); nothing here reads
 * payout_details into a message.
 *
 * SMS only for the terminal states config('realtime.notification_sms_states') allows, and
 * only for a player who explicitly opted in and verified the number (the trait enforces both;
 * SmsChannel enforces the number again).
 */
final class SendWithdrawalStatusNotification implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    use DeliversPlayerNotifications;
    use InteractsWithQueue;

    public function shouldQueue(WithdrawalStatusUpdated $event): bool
    {
        return self::transitionFor($event->withdrawal) !== null;
    }

    /**
     * Typed `object`, not WithdrawalStatusUpdated, on purpose: the framework's event discovery
     * (enabled by Application::configure() in bootstrap/app.php) registers every
     * `handle(SomeEvent $e)` it finds under app/Listeners a second time next to the
     * explicit entry in EventServiceProvider::$listen, and a listener registered twice
     * queues two jobs per fact. The explicit registration is the one that counts.
     */
    public function handle(object $event): void
    {
        if (! $event instanceof WithdrawalStatusUpdated) {
            return;
        }

        $withdrawal = $event->withdrawal;
        $transition = self::transitionFor($withdrawal);

        if ($transition === null) {
            return;
        }

        [$eventType, $copyKey] = $transition;
        $status = (string) $withdrawal->status?->value;
        $claim = 'withdrawal:'.(int) $withdrawal->id.':'.$status;

        if (! $this->claim($claim)) {
            return;
        }

        try {
            $user = $this->owner((int) $withdrawal->user_id);

            if ($user === null) {
                return;
            }

            $locale = $this->localeFor($user);
            $params = [
                'ref' => (string) ($withdrawal->reference_number ?? ('WD-'.$withdrawal->id)),
                'amount' => (string) $withdrawal->amount,
                'currency' => (string) ($withdrawal->currency?->value ?? 'THB'),
            ];

            [$subject, $body] = $this->inAppCopy('withdrawal', $copyKey, $params, $locale);

            $this->pronounceInApp($user, $eventType, $subject, $body, $locale);

            $smsEligible = WithdrawalStatusNotification::smsStateAllowed($status)
                && $this->smsOptedIn((int) $user->getKey(), $eventType);

            $notification = new WithdrawalStatusNotification(
                withdrawal: $withdrawal,
                previousStatus: self::previousStatus($withdrawal),
                currentStatus: $status,
                reason: self::playerFacingReason($withdrawal),
                viaSms: $smsEligible,
            );

            $this->deliverMail($user, $eventType, $subject, $body, $locale, $notification);

            if ($smsEligible) {
                $this->deliverSms($user, $eventType, $subject, $body, $locale, $notification);
            }
        } catch (Throwable $e) {
            $this->unclaim($claim);

            throw $e;
        }
    }

    public function failed(WithdrawalStatusUpdated $event, Throwable $exception): void
    {
        Log::error('notifications.withdrawal.listener_failed', [
            'withdrawal_id' => (int) $event->withdrawal->id,
            'status' => $event->withdrawal->status?->value,
            'reason' => $exception::class,
        ]);
    }

    /**
     * @return array{0: NotificationEventType, 1: string}|null event type, copy key
     */
    public static function transitionFor(Withdrawal $withdrawal): ?array
    {
        return match ($withdrawal->status) {
            WithdrawalStatus::Approved => [NotificationEventType::WithdrawalApproved, 'approved'],
            WithdrawalStatus::Processing => [NotificationEventType::WithdrawalApproved, 'processing'],
            WithdrawalStatus::Completed => [NotificationEventType::WithdrawalPaid, 'completed'],
            WithdrawalStatus::Rejected => [NotificationEventType::WithdrawalRejected, 'rejected'],
            WithdrawalStatus::Failed => [NotificationEventType::WithdrawalRejected, 'failed'],
            default => null,
        };
    }

    private static function previousStatus(Withdrawal $withdrawal): string
    {
        $original = $withdrawal->getOriginal('status');

        if ($original instanceof WithdrawalStatus) {
            return $original->value;
        }

        return is_string($original) && $original !== '' ? $original : 'unknown';
    }

    /**
     * Only the operator's rejection note is player-facing; provider failure text is not.
     */
    private static function playerFacingReason(Withdrawal $withdrawal): ?string
    {
        if ($withdrawal->status !== WithdrawalStatus::Rejected) {
            return null;
        }

        $reason = $withdrawal->getAttribute('rejection_reason');

        return is_string($reason) && trim($reason) !== '' ? mb_substr(trim($reason), 0, 500) : null;
    }
}
