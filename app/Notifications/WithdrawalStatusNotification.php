<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\QueueName;
use App\Enums\WithdrawalStatus;
use App\Models\Withdrawal;
use App\Notifications\Channels\SmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Withdrawal Status Notification.
 *
 * Dispatched on withdrawal lifecycle state changes (approved, processing,
 * completed, rejected, failed) by App\Listeners\Notifications\
 * SendWithdrawalStatusNotification. A completed status is the platform record
 * state; user-facing copy does not infer external provider receipt from that
 * label.
 *
 * NEVER A BANK ACCOUNT NUMBER. The destination, when mentioned at all, is the
 * bank name and the last four digits (see destinationLabel()); the full number,
 * the provider payload and the review metadata never leave the server.
 *
 * CHANNELS. Mail always; SMS (App\Notifications\Channels\SmsChannel) only for
 * the terminal states listed in config('realtime.notification_sms_states') and
 * only when the sender has established the player opted in ($viaSms) — the
 * channel itself still refuses an unverified number. The in-app row is the
 * NotificationDispatchService row the listener writes first, not the
 * framework's `database` channel (the `notifications` table is this
 * application's own fingerprinted schema).
 */
class WithdrawalStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly Withdrawal $withdrawal,
        public readonly string $previousStatus,
        public readonly string $currentStatus,
        public readonly ?string $reason = null,
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

        if ($this->viaSms && self::smsStateAllowed($this->currentStatus)) {
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

    /**
     * Whether a withdrawal state is one the operator allows on the SMS lane.
     */
    public static function smsStateAllowed(string $status): bool
    {
        $allowed = config('realtime.notification_sms_states', ['completed', 'rejected', 'failed']);

        return is_array($allowed) && in_array($status, $allowed, true);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $params = $this->params();

        $subject = match ($this->currentStatus) {
            WithdrawalStatus::Completed->value => __('notifications.mail.withdrawal.subject_completed', $params),
            WithdrawalStatus::Processing->value => __('notifications.mail.withdrawal.subject_processing', $params),
            WithdrawalStatus::UnderReview->value => __('notifications.mail.withdrawal.subject_under_review', $params),
            WithdrawalStatus::Approved->value => __('notifications.mail.withdrawal.subject_approved', $params),
            WithdrawalStatus::Failed->value => __('notifications.mail.withdrawal.subject_failed', $params),
            WithdrawalStatus::Rejected->value => __('notifications.mail.withdrawal.subject_rejected', $params),
            default => __('notifications.mail.withdrawal.subject_default', $params),
        };

        $line = match ($this->currentStatus) {
            WithdrawalStatus::Completed->value => __('notifications.mail.withdrawal.line_completed', $params),
            WithdrawalStatus::Processing->value => __('notifications.mail.withdrawal.line_processing', $params),
            WithdrawalStatus::UnderReview->value => __('notifications.mail.withdrawal.line_under_review', $params),
            WithdrawalStatus::Approved->value => __('notifications.mail.withdrawal.line_approved', $params),
            WithdrawalStatus::Failed->value => __('notifications.mail.withdrawal.line_failed', $params),
            WithdrawalStatus::Rejected->value => $this->rejectedLine($params),
            default => __('notifications.mail.withdrawal.line_default', $params),
        };

        $mail = (new MailMessage)
            ->subject((string) $subject)
            ->line((string) $line);

        $destination = $this->destinationLabel();

        if ($destination !== null) {
            $mail->line((string) __('notifications.mail.withdrawal.destination', ['destination' => $destination]));
        }

        return $mail
            ->action((string) __('notifications.mail.withdrawal.action'), url('/withdraw'))
            ->line((string) __('notifications.mail.withdrawal.footer'));
    }

    /**
     * The short text for the SMS lane; no destination, no reason text.
     */
    public function toSms(object $notifiable): string
    {
        $params = $this->params() + ['app' => (string) config('app.name', 'Thai Lottery')];

        $key = match ($this->currentStatus) {
            WithdrawalStatus::Completed->value => 'notifications.sms.withdrawal_completed',
            WithdrawalStatus::Rejected->value => 'notifications.sms.withdrawal_rejected',
            WithdrawalStatus::Failed->value => 'notifications.sms.withdrawal_failed',
            default => 'notifications.sms.withdrawal_default',
        };

        return (string) __($key, $params);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'withdrawal_id' => (int) $this->withdrawal->id,
            'reference_number' => $this->reference(),
            'amount' => (string) $this->withdrawal->amount,
            'currency' => $this->currencyCode(),
            'channel' => (string) ($this->withdrawal->getAttribute('channel') ?? $this->withdrawal->method?->value ?? ''),
            'previous_status' => $this->previousStatus,
            'current_status' => $this->currentStatus,
            'reason' => $this->reason,
            'destination' => $this->destinationLabel(),
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Bank name + last four digits, or null when the row carries no destination.
     * Any full account number found in payout_details is reduced to its last four.
     */
    public function destinationLabel(): ?string
    {
        $details = $this->withdrawal->getAttribute('payout_details');

        if (! is_array($details) || $details === []) {
            return null;
        }

        $bank = $details['bank'] ?? $details['bank_name'] ?? $details['provider'] ?? null;
        $last4 = $details['account_last4'] ?? null;

        if ($last4 === null) {
            foreach (['account_number', 'bank_account', 'iban', 'wallet_number', 'phone', 'msisdn'] as $key) {
                $value = $details[$key] ?? null;

                if (is_string($value) || is_int($value)) {
                    $digits = preg_replace('/[^0-9A-Za-z]/', '', (string) $value) ?? '';

                    if ($digits !== '') {
                        $last4 = substr($digits, -4);
                        break;
                    }
                }
            }
        }

        if ($last4 === null && $bank === null) {
            return null;
        }

        $masked = $last4 !== null ? '****'.substr((string) $last4, -4) : null;

        return trim(implode(' ', array_filter([is_string($bank) ? $bank : null, $masked])));
    }

    /**
     * @return array<string, string>
     */
    private function params(): array
    {
        return [
            'amount' => (string) $this->withdrawal->amount,
            'currency' => $this->currencyCode(),
            'ref' => $this->reference(),
            'status' => str_replace('_', ' ', $this->currentStatus),
        ];
    }

    /**
     * @param  array<string, string>  $params
     */
    private function rejectedLine(array $params): string
    {
        $parts = [(string) __('notifications.mail.withdrawal.line_rejected', $params)];

        if ($this->reason !== null && trim($this->reason) !== '') {
            $parts[] = (string) __('notifications.mail.withdrawal.review_note', ['reason' => trim($this->reason)]);
        }

        $parts[] = (string) __('notifications.mail.withdrawal.line_rejected_tail');

        return implode(' ', $parts);
    }

    private function reference(): string
    {
        return (string) ($this->withdrawal->reference_number ?? ('WD-'.$this->withdrawal->id));
    }

    private function currencyCode(): string
    {
        $currency = $this->withdrawal->getAttribute('currency');

        if ($currency instanceof \BackedEnum) {
            return (string) $currency->value;
        }

        return is_string($currency) && $currency !== '' ? strtoupper($currency) : 'THB';
    }
}
