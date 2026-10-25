<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\DepositStatus;
use App\Enums\PaymentStatus;
use App\Enums\QueueName;
use App\Models\Deposit;
use App\Models\PaymentTransaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Payment Status Change Notification.
 *
 * Dispatched on deposit or payment status updates by
 * App\Listeners\Notifications\SendDepositStatusNotification. Never exposes
 * provider credentials, raw webhook payloads or evidence.
 *
 * CHANNELS. Mail, through the framework. The in-app row is NOT the framework's
 * `database` channel: this application's `notifications` table is the
 * fingerprinted, preference-aware row App\Services\Notification\
 * NotificationDispatchService writes (it has no notifiable_type/data columns),
 * and the listener writes that row before it sends this mail. Every sentence
 * is a lang key (en/th/bn); the notification's locale is set by the sender.
 *
 * QUEUE. Queued after commit on the notifications lane with a bounded retry.
 */
class PaymentStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly PaymentTransaction|Deposit $transaction,
        public readonly string $previousStatus,
        public readonly string $currentStatus,
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
        return ['mail'];
    }

    /**
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        return ['mail' => QueueName::Notifications->value];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $params = [
            'amount' => $this->amount(),
            'currency' => $this->currencyCode(),
            'ref' => $this->reference(),
            'status' => str_replace('_', ' ', $this->currentStatus),
        ];

        $subject = match ($this->currentStatus) {
            PaymentStatus::Captured->value, DepositStatus::Confirmed->value => __('notifications.mail.payment.subject_captured', $params),
            PaymentStatus::Failed->value => __('notifications.mail.payment.subject_failed', $params),
            PaymentStatus::Cancelled->value => __('notifications.mail.payment.subject_cancelled', $params),
            default => __('notifications.mail.payment.subject_default', $params),
        };

        $line = match ($this->currentStatus) {
            PaymentStatus::Captured->value, DepositStatus::Confirmed->value => __('notifications.mail.payment.line_captured', $params),
            PaymentStatus::Failed->value => __('notifications.mail.payment.line_failed', $params),
            PaymentStatus::Cancelled->value => __('notifications.mail.payment.line_cancelled', $params),
            PaymentStatus::Refunded->value => __('notifications.mail.payment.line_refunded', $params),
            DepositStatus::Pending->value, DepositStatus::Approved->value, DepositStatus::Processing->value => __('notifications.mail.payment.line_pending', $params),
            default => __('notifications.mail.payment.line_default', $params),
        };

        return (new MailMessage)
            ->subject((string) $subject)
            ->line((string) $line)
            ->action((string) __('notifications.mail.payment.action'), url('/wallet'))
            ->line((string) __('notifications.mail.payment.footer'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'transaction_id' => (int) $this->transaction->id,
            'reference_id' => $this->reference(),
            'amount' => $this->amount(),
            'currency' => $this->currencyCode(),
            'channel' => $this->channelCode(),
            'previous_status' => $this->previousStatus,
            'current_status' => $this->currentStatus,
            'updated_at' => now()->toIso8601String(),
        ];
    }

    private function amount(): string
    {
        return (string) $this->transaction->amount;
    }

    private function currencyCode(): string
    {
        $currency = $this->transaction->getAttribute('currency');

        if ($currency instanceof \BackedEnum) {
            return (string) $currency->value;
        }

        return is_string($currency) && $currency !== '' ? strtoupper($currency) : 'THB';
    }

    private function reference(): string
    {
        if ($this->transaction instanceof Deposit) {
            return (string) ($this->transaction->reference_number ?? ('DEP-'.$this->transaction->id));
        }

        return (string) $this->transaction->reference_id;
    }

    private function channelCode(): string
    {
        $channel = $this->transaction instanceof Deposit
            ? $this->transaction->getAttribute('method')
            : $this->transaction->getAttribute('channel');

        if ($channel instanceof \BackedEnum) {
            return (string) $channel->value;
        }

        return is_string($channel) && $channel !== '' ? $channel : 'unknown';
    }
}
