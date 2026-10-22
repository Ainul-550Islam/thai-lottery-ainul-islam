<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\Currency;
use App\Enums\PrizeDisbursementStatus;
use App\Models\PrizeDisbursement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Prize Won Notification.
 *
 * Dispatched only after authoritative prize settlement eligibility and confirmation.
 */
class PrizeWonNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly PrizeDisbursement $disbursement,
        public readonly string $tierName,
        public readonly string $ticketNumber,
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
        $amount = (string) $this->disbursement->prize_amount;
        $currency = $this->currencyCode();
        $drawId = (string) $this->disbursement->draw_id;
        $status = $this->disbursement->status;
        $statusValue = $status instanceof PrizeDisbursementStatus
            ? $status->value
            : (string) $status;

        return (new MailMessage)
            ->subject('Prize status update')
            ->line("A prize result was recorded for ticket #{$this->ticketNumber} in Draw #{$drawId} ({$this->tierName}).")
            ->line("Recorded prize amount: {$amount} {$currency}")
            ->line('Current disbursement status: '.str_replace('_', ' ', $statusValue).'.')
            ->line('This notification does not by itself confirm that funds are available for withdrawal. Sign in to review your current wallet and payout status.')
            ->action('Review My Bets', url('/bets'));
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
        return [
            'disbursement_id' => (int) $this->disbursement->id,
            'draw_id' => (int) $this->disbursement->draw_id,
            'ticket_number' => $this->ticketNumber,
            'tier_name' => $this->tierName,
            'prize_amount' => (string) $this->disbursement->prize_amount,
            'currency' => $this->currencyCode(),
            'status' => (string) $this->disbursement->status->value,
            'reference_number' => (string) $this->disbursement->reference_number,
            'created_at' => now()->toIso8601String(),
        ];
    }
}
