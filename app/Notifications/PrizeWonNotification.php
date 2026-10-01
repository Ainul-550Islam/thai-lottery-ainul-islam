<?php

declare(strict_types=1);

namespace App\Notifications;

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
        $amount = (string) $this->disbursement->prize_amount;
        $currency = $this->disbursement->currency->value ?? 'THB';
        $drawId = (string) $this->disbursement->draw_id;

        return (new MailMessage)
            ->subject('Congratulations! You Won a Lottery Prize!')
            ->line("Your ticket #{$this->ticketNumber} matched the {$this->tierName} prize in Draw #{$drawId}.")
            ->line("Prize Amount: {$amount} {$currency}")
            ->action('View My Winnings', url('/player/bets'))
            ->line('The winnings have been credited to your platform wallet.');
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
            'currency' => $this->disbursement->currency->value ?? 'THB',
            'status' => (string) $this->disbursement->status->value,
            'reference_number' => (string) $this->disbursement->reference_number,
            'created_at' => now()->toIso8601String(),
        ];
    }
}
