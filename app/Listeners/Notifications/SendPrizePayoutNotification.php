<?php

declare(strict_types=1);

namespace App\Listeners\Notifications;

use App\Enums\NotificationEventType;
use App\Enums\NotificationPriority;
use App\Enums\PayoutStatus;
use App\Events\PrizePayoutCompleted;
use App\Listeners\Notifications\Concerns\DeliversPlayerNotifications;
use App\Models\Bet;
use App\Notifications\PrizeWonNotification;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells the winner that a prize payout landed.
 *
 * Fires on PrizePayoutCompleted, which is dispatched after the paying transaction committed,
 * so this listener never describes money that rolled back. It uses PrizeWonNotification's
 * wording, which already says the notification alone does not confirm funds are available
 * for withdrawal.
 *
 * OWNER ONLY. The event carries both the payout row and the winner; when the two disagree
 * (the payout's user_id is not the winner) nothing is sent and the mismatch is logged, because
 * the alternative is telling one player about another player's money.
 */
final class SendPrizePayoutNotification implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    use DeliversPlayerNotifications;
    use InteractsWithQueue;

    public function shouldQueue(PrizePayoutCompleted $event): bool
    {
        return $event->payout->status === PayoutStatus::Completed
            && (int) $event->payout->user_id === (int) $event->winner->getKey();
    }

    /**
     * Typed `object`, not PrizePayoutCompleted, on purpose: the framework's event discovery
     * (enabled by Application::configure() in bootstrap/app.php) registers every
     * `handle(SomeEvent $e)` it finds under app/Listeners a second time next to the
     * explicit entry in EventServiceProvider::$listen, and a listener registered twice
     * queues two jobs per fact. The explicit registration is the one that counts.
     */
    public function handle(object $event): void
    {
        if (! $event instanceof PrizePayoutCompleted) {
            return;
        }

        $payout = $event->payout;

        if ($payout->status !== PayoutStatus::Completed) {
            return;
        }

        if ((int) $payout->user_id !== (int) $event->winner->getKey()) {
            Log::warning('notifications.payout.owner_mismatch', [
                'payout_id' => (int) $payout->id,
            ]);

            return;
        }

        $claim = 'payout:'.(int) $payout->id.':'.PayoutStatus::Completed->value;

        if (! $this->claim($claim)) {
            return;
        }

        try {
            $user = $this->owner((int) $payout->user_id);

            if ($user === null) {
                return;
            }

            $locale = $this->localeFor($user);
            $params = [
                'ref' => (string) ($payout->reference_number ?? ('PO-'.$payout->id)),
                'amount' => (string) $payout->amount,
                'currency' => (string) $payout->currency->value,
                'draw' => (string) $payout->draw_id,
            ];

            $subject = (string) __('notifications.inapp.payout.subject', $params, $locale);
            $body = (string) __('notifications.inapp.payout.body', $params, $locale);
            $eventType = NotificationEventType::BetWon;

            $this->pronounceInApp($user, $eventType, $subject, $body, $locale, NotificationPriority::High);

            $smsEligible = $this->smsOptedIn((int) $user->getKey(), $eventType);

            $notification = new PrizeWonNotification(
                disbursement: $payout,
                tierName: (string) __('notifications.mail.prize.tier_payout', [], $locale),
                ticketNumber: self::ticketReference($payout->bet_id),
                viaSms: $smsEligible,
            );

            $this->deliverMail($user, $eventType, $subject, $body, $locale, $notification, NotificationPriority::High);

            if ($smsEligible) {
                $this->deliverSms($user, $eventType, $subject, $body, $locale, $notification, NotificationPriority::High);
            }
        } catch (Throwable $e) {
            $this->unclaim($claim);

            throw $e;
        }
    }

    public function failed(PrizePayoutCompleted $event, Throwable $exception): void
    {
        Log::error('notifications.payout.listener_failed', [
            'payout_id' => (int) $event->payout->id,
            'reason' => $exception::class,
        ]);
    }

    /**
     * The bet's own reference for the mail; never another table's id when the bet is gone.
     */
    private static function ticketReference(?int $betId): string
    {
        if ($betId === null) {
            return '-';
        }

        $bet = Bet::query()->find($betId);

        return $bet instanceof Bet ? (string) $bet->bet_number : '-';
    }
}
