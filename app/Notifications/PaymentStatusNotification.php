<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\PaymentStatus;
use App\Models\PaymentTransaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Payment Status Change Notification.
 *
 * Dispatched on deposit or payment status updates. Never exposes provider credentials
 * or raw webhook payloads.
 */
class PaymentStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly PaymentTransaction $transaction,
        public readonly string $previousStatus,
        public readonly string $currentStatus,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $amount = (string) $this->transaction->amount;
        $currency = $this->transaction->currency->value ?? 'THB';
        $ref = (string) $this->transaction->reference_id;

        $subject = match ($this->currentStatus) {
            PaymentStatus::Captured->value => 'Payment Confirmed: '.$amount.' '.$currency,
            PaymentStatus::Failed->value => 'Payment Failed: '.$amount.' '.$currency,
            PaymentStatus::Cancelled->value => 'Payment Cancelled',
            default => 'Payment Update: '.$amount.' '.$currency,
        };

        $line = match ($this->currentStatus) {
            PaymentStatus::Captured->value => "Your payment of {$amount} {$currency} (Ref: {$ref}) has been successfully processed and credited to your wallet.",
            PaymentStatus::Failed->value => "Your payment of {$amount} {$currency} (Ref: {$ref}) could not be completed.",
            PaymentStatus::Cancelled->value => "Your payment of {$amount} {$currency} (Ref: {$ref}) was cancelled.",
            default => "Your payment of {$amount} {$currency} (Ref: {$ref}) is currently in status: {$this->currentStatus}.",
        };

        return (new MailMessage)
            ->subject($subject)
            ->line($line)
            ->action('View Wallet', url('/player/wallet'))
            ->line('Thank you for using our platform.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'transaction_id' => (int) $this->transaction->id,
            'reference_id' => (string) $this->transaction->reference_id,
            'amount' => (string) $this->transaction->amount,
            'currency' => $this->transaction->currency->value ?? 'THB',
            'channel' => $this->transaction->channel->value ?? 'unknown',
            'previous_status' => $this->previousStatus,
            'current_status' => $this->currentStatus,
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
