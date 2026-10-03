<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\KycStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * KYC Verification Status Notification.
 *
 * Dispatched on KYC document or account verification decisions.
 * Never includes sensitive document content, national IDs, or raw file paths.
 */
class KycStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $status,
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
        $subject = match ($this->status) {
            KycStatus::Verified->value, 'approved' => 'Account Verification Approved',
            KycStatus::Rejected->value, 'rejected' => 'Account Verification Requires Attention',
            KycStatus::UnderReview->value, 'under_review' => 'Account Verification Under Review',
            default => 'Account Verification Update',
        };

        $line = match ($this->status) {
            KycStatus::Verified->value, 'approved' => 'Your identity documents have been verified. Your account is now fully approved for enhanced limits and withdrawals.',
            KycStatus::Rejected->value, 'rejected' => 'Your submitted verification document could not be approved. Reason: '.($this->reason ?? 'Document unreadable or invalid format.').' Please upload a clear replacement document.',
            KycStatus::UnderReview->value, 'under_review' => 'Your verification documents are currently being reviewed by our compliance team.',
            default => "Your account verification status is currently: {$this->status}.",
        };

        $mail = (new MailMessage)
            ->subject($subject)
            ->line($line);

        if ($this->status === KycStatus::Rejected->value || $this->status === 'rejected') {
            $mail->action('Resubmit Verification', url('/account/verification'));
        } else {
            $mail->action('View Account Status', url('/player/profile'));
        }

        return $mail->line('Thank you for ensuring platform safety and compliance.');
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
