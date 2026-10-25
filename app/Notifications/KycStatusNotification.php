<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\KycStatus;
use App\Enums\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * KYC Verification Status Notification.
 *
 * Dispatched on KYC document or account verification decisions by
 * App\Listeners\Notifications\SendKycStatusNotification. Never includes
 * sensitive document content, national IDs, or raw file paths; the only free
 * text is the review note an operator wrote for the player.
 *
 * CHANNELS. Mail. The in-app row is written by the listener through
 * NotificationDispatchService (this application's own `notifications` table,
 * not the framework's `database` channel schema). Copy is localized (en/th/bn).
 */
class KycStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $status,
        public readonly ?string $reason = null,
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
        $params = ['status' => str_replace('_', ' ', $this->status)];

        $subject = match ($this->status) {
            KycStatus::Verified->value, 'approved' => __('notifications.mail.kyc.subject_approved', $params),
            KycStatus::Rejected->value, 'rejected' => __('notifications.mail.kyc.subject_rejected', $params),
            KycStatus::UnderReview->value, 'under_review' => __('notifications.mail.kyc.subject_under_review', $params),
            default => __('notifications.mail.kyc.subject_default', $params),
        };

        $line = match ($this->status) {
            KycStatus::Verified->value, 'approved' => (string) __('notifications.mail.kyc.line_approved', $params),
            KycStatus::Rejected->value, 'rejected' => $this->rejectedLine(),
            KycStatus::UnderReview->value, 'under_review' => (string) __('notifications.mail.kyc.line_under_review', $params),
            KycStatus::Expired->value => (string) __('notifications.mail.kyc.line_expired', $params),
            default => (string) __('notifications.mail.kyc.line_default', $params),
        };

        return (new MailMessage)
            ->subject((string) $subject)
            ->line($line)
            ->action((string) __('notifications.mail.kyc.action'), url('/account/verification'))
            ->line((string) __('notifications.mail.kyc.footer'));
    }

    private function rejectedLine(): string
    {
        $parts = [(string) __('notifications.mail.kyc.line_rejected')];

        if ($this->reason !== null && trim($this->reason) !== '') {
            $parts[] = (string) __('notifications.mail.kyc.review_note', ['reason' => trim($this->reason)]);
        }

        $parts[] = (string) __('notifications.mail.kyc.line_rejected_tail');

        return implode(' ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'status' => $this->status,
            'reason' => $this->reason,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
