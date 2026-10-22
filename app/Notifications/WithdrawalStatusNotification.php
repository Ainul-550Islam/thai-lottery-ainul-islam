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
 * completed, rejected, reversed). A completed status is the platform record state;
 * user-facing copy does not infer external provider receipt from that label.
 */
class WithdrawalStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Withdrawal $withdrawal,
        public readonly string $previousStatus,
        public readonly string $currentStatus,
        public readonly ?string $reason = null,
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
        $amount = (string) $this->withdrawal->amount;
        $currency = $this->withdrawal->currency->value ?? 'THB';
        $ref = (string) ($this->withdrawal->reference_number ?? ('WD-'.$this->withdrawal->id));

        $subject = match ($this->currentStatus) {
            WithdrawalStatus::Completed->value => 'Withdrawal Completed: '.$amount.' '.$currency,
            WithdrawalStatus::Processing->value => 'Withdrawal Processing: '.$amount.' '.$currency,
            WithdrawalStatus::UnderReview->value => 'Withdrawal Under Review: '.$amount.' '.$currency,
            WithdrawalStatus::Failed->value => 'Withdrawal Failed: '.$amount.' '.$currency,
            WithdrawalStatus::Rejected->value => 'Withdrawal Rejected',
            default => 'Withdrawal Update: '.$amount.' '.$currency,
        };

        $line = match ($this->currentStatus) {
            WithdrawalStatus::Completed->value => "Your withdrawal of {$amount} {$currency} (Ref: {$ref}) is recorded as completed. Check your destination account or provider statement for receipt details.",
            WithdrawalStatus::Processing->value => "Your withdrawal of {$amount} {$currency} (Ref: {$ref}) is recorded as processing. No completion time is promised.",
            WithdrawalStatus::UnderReview->value => "Your withdrawal of {$amount} {$currency} (Ref: {$ref}) is under review. Check your account for the current status and any instructions.",
            WithdrawalStatus::Failed->value => "Your withdrawal of {$amount} {$currency} (Ref: {$ref}) is recorded as failed. Check your account for the current wallet status before retrying.",
            WithdrawalStatus::Rejected->value => "Your withdrawal of {$amount} {$currency} (Ref: {$ref}) was rejected.".($this->reason !== null && $this->reason !== '' ? ' Review note: '.$this->reason : '').' Check your account for current wallet status and next steps.',
            default => "Your withdrawal request of {$amount} {$currency} (Ref: {$ref}) status has changed to: {$this->currentStatus}.",
        };

        return (new MailMessage)
            ->subject($subject)
            ->line($line)
            ->action('View Withdrawal History', url('/withdraw'))
            ->line('If you do not recognize this withdrawal, sign in and review your account security.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'withdrawal_id' => (int) $this->withdrawal->id,
            'reference_number' => (string) ($this->withdrawal->reference_number ?? ('WD-'.$this->withdrawal->id)),
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
