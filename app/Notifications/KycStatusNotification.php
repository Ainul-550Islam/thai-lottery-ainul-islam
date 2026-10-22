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
            KycStatus::Verified->value, 'approved' => 'Your verification status was marked approved. Sign in to review any remaining account or withdrawal requirements; approval does not guarantee that every account action is available.',
            KycStatus::Rejected->value, 'rejected' => 'Your submitted verification document was not approved.'.($this->reason !== null && $this->reason !== '' ? ' Review note: '.$this->reason : '').' Sign in to review the instructions shown in your account.',
            KycStatus::UnderReview->value, 'under_review' => 'Your verification status is under review. Check your account for the current status and any follow-up instructions; no fixed completion time is promised.',
            default => "Your account verification status is currently: {$this->status}.",
        };

        $mail = (new MailMessage)
            ->subject($subject)
            ->line($line);

        $mail->action('View Account Verification Status', url('/account/verification'));

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
