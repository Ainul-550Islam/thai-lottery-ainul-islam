<?php

declare(strict_types=1);

namespace App\Listeners\Notifications;

use App\Enums\DepositStatus;
use App\Enums\NotificationEventType;
use App\Events\DepositStatusUpdated;
use App\Listeners\Notifications\Concerns\DeliversPlayerNotifications;
use App\Models\Deposit;
use App\Notifications\PaymentStatusNotification;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells the depositor when their deposit reached a state that matters to them.
 *
 * MEANINGFUL TRANSITIONS ONLY. `confirmed` (the money is in the wallet), the failure family
 * (`failed`, `rejected`, `cancelled`, `refunded`) and `processing` (the deposit is under
 * review, in-app only). `pending` and `approved` are internal hops the deposit page already
 * shows; a message per hop is noise a player learns to ignore before the one that matters.
 *
 * IDEMPOTENT. One claim per (deposit id, status) for the dedupe window, and the row
 * fingerprint beneath it, so a queue retry or a replayed webhook never sends twice.
 */
final class SendDepositStatusNotification implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    use DeliversPlayerNotifications;
    use InteractsWithQueue;

    public function shouldQueue(DepositStatusUpdated $event): bool
    {
        return self::transitionFor($event->deposit) !== null;
    }

    /**
     * Typed `object`, not DepositStatusUpdated, on purpose: the framework's event discovery
     * (enabled by Application::configure() in bootstrap/app.php) registers every
     * `handle(SomeEvent $e)` it finds under app/Listeners a second time next to the
     * explicit entry in EventServiceProvider::$listen, and a listener registered twice
     * queues two jobs per fact. The explicit registration is the one that counts.
     */
    public function handle(object $event): void
    {
        if (! $event instanceof DepositStatusUpdated) {
            return;
        }

        $deposit = $event->deposit;
        $transition = self::transitionFor($deposit);

        if ($transition === null) {
            return;
        }

        [$eventType, $copyKey, $withMail] = $transition;
        $status = (string) $deposit->status?->value;
        $claim = 'deposit:'.(int) $deposit->id.':'.$status;

        if (! $this->claim($claim)) {
            return;
        }

        try {
            $user = $this->owner((int) $deposit->user_id);

            if ($user === null) {
                return;
            }

            $locale = $this->localeFor($user);
            $params = [
                'ref' => (string) ($deposit->reference_number ?? ('DEP-'.$deposit->id)),
                'amount' => (string) $deposit->amount,
                'currency' => (string) ($deposit->currency?->value ?? 'THB'),
            ];

            [$subject, $body] = $this->inAppCopy('deposit', $copyKey, $params, $locale);

            $this->pronounceInApp($user, $eventType, $subject, $body, $locale);

            if ($withMail) {
                $notification = new PaymentStatusNotification(
                    transaction: $deposit,
                    previousStatus: self::previousStatus($deposit),
                    currentStatus: $status,
                );

                $this->deliverMail($user, $eventType, $subject, $body, $locale, $notification);
            }
        } catch (Throwable $e) {
            $this->unclaim($claim);

            throw $e;
        }
    }

    public function failed(DepositStatusUpdated $event, Throwable $exception): void
    {
        Log::error('notifications.deposit.listener_failed', [
            'deposit_id' => (int) $event->deposit->id,
            'status' => $event->deposit->status?->value,
            'reason' => $exception::class,
        ]);
    }

    /**
     * @return array{0: NotificationEventType, 1: string, 2: bool}|null event type, copy key, send mail
     */
    public static function transitionFor(Deposit $deposit): ?array
    {
        return match ($deposit->status) {
            DepositStatus::Confirmed => [NotificationEventType::DepositConfirmed, 'confirmed', true],
            DepositStatus::Failed, DepositStatus::Rejected => [NotificationEventType::DepositFailed, 'failed', true],
            DepositStatus::Cancelled => [NotificationEventType::DepositFailed, 'cancelled', true],
            DepositStatus::Refunded => [NotificationEventType::DepositFailed, 'refunded', true],
            DepositStatus::Processing => [NotificationEventType::Manual, 'review', false],
            default => null,
        };
    }

    private static function previousStatus(Deposit $deposit): string
    {
        $original = $deposit->getOriginal('status');

        if ($original instanceof DepositStatus) {
            return $original->value;
        }

        return is_string($original) && $original !== '' ? $original : 'unknown';
    }
}
