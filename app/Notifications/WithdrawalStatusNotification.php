<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\WithdrawalStatus;
use App\Models\Withdrawal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Withdrawal Status Notification.
 *
 * Dispatched on withdrawal lifecycle state changes (requested, pending, manual review,
 * completed, rejected, reversed). Only completed represents verified settlement.
 */
class WithdrawalStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Withdrawal $withdrawal,
        public readonly string $previousStatus,
        public readonly string $currentStatus,
        public readonly ?string $reason = null,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $amount = (string) $this->withdrawal->amount;
        $currency = $this->withdrawal->currency->value ?? 'THB';
        $ref = (string) ($this->withdrawal->reference_number ?? ('WD-' . $this->withdrawal->id));

        $subject = match ($this->currentStatus) {
            WithdrawalStatus::Completed->value => 'Withdrawal Completed: ' . $amount . ' ' . $currency,
            WithdrawalStatus::Processing->value, WithdrawalStatus::Dispatched->value => 'Withdrawal Processing: ' . $amount . ' ' . $currency,
            WithdrawalStatus::UnderReview->value => 'Withdrawal Under Review: ' . $amount . ' ' . $currency,
            WithdrawalStatus::Failed->value => 'Withdrawal Failed: ' . $amount . ' ' . $currency,
            WithdrawalStatus::Rejected->value => 'Withdrawal Rejected',
            WithdrawalStatus::Reversed->value => 'Withdrawal Reversed: ' . $amount . ' ' . $currency,
            default => 'Withdrawal Update: ' . $amount . ' ' . $currency,
        };

        $line = match ($this->currentStatus) {
            WithdrawalStatus::Completed->value => "Your withdrawal of {$amount} {$currency} (Ref: {$ref}) has been settled and sent to your payout destination.",
            WithdrawalStatus::Processing->value, WithdrawalStatus::Dispatched->value => "Your withdrawal of {$amount} {$currency} (Ref: {$ref}) is currently being processed by the payment provider.",
            WithdrawalStatus::UnderReview->value => "Your withdrawal of {$amount} {$currency} (Ref: {$ref}) is undergoing routine security review.",
            WithdrawalStatus::Failed->value => "Your withdrawal of {$amount} {$currency} (Ref: {$ref}) failed to disburse. Reserved funds have been restored to your wallet.",
            WithdrawalStatus::Rejected->value => "Your withdrawal of {$amount} {$currency} (Ref: {$ref}) was rejected. Reason: " . ($this->reason ?? 'Compliance or account check failed.') . " Funds have been refunded to your wallet.",
            WithdrawalStatus::Reversed->value => "Your withdrawal of {$amount} {$currency} (Ref: {$ref}) was reversed and returned to your balance.",
            default => "Your withdrawal request of {$amount} {$currency} (Ref: {$ref}) status has changed to: {$this->currentStatus}.",
        };

        return (new MailMessage)
            ->subject($subject)
            ->line($line)
            ->action('View Withdrawal History', url('/player/withdraw'))
            ->line('If you did not request this withdrawal, please contact security support immediately.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'withdrawal_id' => (int) $this->withdrawal->id,
            'reference_number' => (string) ($this->withdrawal->reference_number ?? ('WD-' . $this->withdrawal->id)),
            'amount' => (string) $this->withdrawal->amount,
            'currency' => $this->withdrawal->currency->value ?? 'THB',
            'channel' => (string) $this->withdrawal->channel,
            'previous_status' => $this->previousStatus,
            'current_status' => $this->currentStatus,
            'reason' => $this->reason,
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
