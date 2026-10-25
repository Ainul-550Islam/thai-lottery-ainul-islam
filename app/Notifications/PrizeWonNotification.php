<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\Currency;
use App\Enums\PrizeDisbursementStatus;
use App\Enums\QueueName;
use App\Models\Payout;
use App\Models\PrizeDisbursement;
use App\Notifications\Channels\SmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Prize Won Notification.
 *
 * Dispatched only after authoritative prize settlement eligibility and
 * confirmation: App\Listeners\Notifications\SendPrizePayoutNotification sends
 * it on PrizePayoutCompleted (a completed Payout row), and the prize-claim lane
 * may send it with a PrizeDisbursement. The copy records the platform's own
 * state and states explicitly that the notification alone does not confirm
 * that funds are available for withdrawal.
 *
 * CHANNELS. Mail always; SMS only when the sender established the player
 * opted in ($viaSms), through App\Notifications\Channels\SmsChannel, which
 * still refuses an unverified number. The in-app row is written by the
 * listener through NotificationDispatchService.
 */
class PrizeWonNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly PrizeDisbursement|Payout $disbursement,
        public readonly string $tierName,
        public readonly string $ticketNumber,
        public readonly bool $viaSms = false,
    ) {
        $this->afterCommit();
        $this->onQueue(QueueName::Notifications->value);
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['mail'];

        if ($this->viaSms) {
            $channels[] = SmsChannel::class;
        }

        return $channels;
    }

    /**
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        return [
            'mail' => QueueName::Notifications->value,
            SmsChannel::class => QueueName::Notifications->value,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject((string) __('notifications.mail.prize.subject'))
            ->line((string) __('notifications.mail.prize.line_result', [
                'ticket' => $this->ticketNumber,
                'draw' => (string) $this->disbursement->draw_id,
                'tier' => $this->tierName,
            ]))
            ->line((string) __('notifications.mail.prize.line_amount', [
                'amount' => $this->amount(),
                'currency' => $this->currencyCode(),
            ]))
            ->line((string) __('notifications.mail.prize.line_status', ['status' => $this->statusWord()]))
            ->line((string) __('notifications.mail.prize.line_disclaimer'))
            ->action((string) __('notifications.mail.prize.action'), url('/bets'));
    }

    public function toSms(object $notifiable): string
    {
        return (string) __('notifications.sms.prize', [
            'app' => (string) config('app.name', 'Thai Lottery'),
            'ref' => $this->reference(),
            'amount' => $this->amount(),
            'currency' => $this->currencyCode(),
        ]);
    }

    private function amount(): string
    {
        if ($this->disbursement instanceof Payout) {
            return (string) $this->disbursement->amount;
        }

        return (string) ($this->disbursement->prize_amount ?? $this->disbursement->amount);
    }

    private function reference(): string
    {
        return (string) ($this->disbursement->reference_number ?? ('PO-'.$this->disbursement->id));
    }

    private function statusWord(): string
    {
        $status = $this->disbursement->status;
        $value = $status instanceof \BackedEnum ? (string) $status->value : (string) $status;

        return str_replace('_', ' ', $value);
    }

    private function currencyCode(): string
    {
        $currencyAttribute = $this->disbursement->getAttribute('currency');

        if ($currencyAttribute instanceof Currency) {
            return $currencyAttribute->value;
        }

        $currency = strtoupper(trim((string) $currencyAttribute));

        return Currency::tryFromCode($currency)?->value ?? Currency::primary()->value;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $status = $this->disbursement->status;

        return [
            'disbursement_id' => (int) $this->disbursement->id,
            'source' => $this->disbursement instanceof Payout ? 'payout' : 'prize_disbursement',
            'draw_id' => (int) $this->disbursement->draw_id,
            'ticket_number' => $this->ticketNumber,
            'tier_name' => $this->tierName,
            'prize_amount' => $this->amount(),
            'currency' => $this->currencyCode(),
            'status' => $status instanceof PrizeDisbursementStatus || $status instanceof \BackedEnum ? (string) $status->value : (string) $status,
            'reference_number' => $this->reference(),
            'created_at' => now()->toIso8601String(),
        ];
    }
}
