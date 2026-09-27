<?php

namespace Database\Factories;

use App\Enums\Currency;
use App\Enums\PayoutStatus;
use App\Models\Draw;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Payout>
 *
 * A payout ROW is not money. Crediting a wallet and posting the ledger is the
 * settlement service's job — a factory only writes the record.
 */
class PayoutFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'reference_number' => 'PO-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
            'draw_id' => Draw::factory(),
            'user_id' => User::factory(),
            'bet_id' => null,
            'ticket_id' => null,
            'wallet_id' => null,
            'financial_transaction_id' => null,
            'status' => PayoutStatus::Pending,
            'currency' => Currency::THB,
            'amount' => '7000.00',
            'multiplier' => '70.0000',
        ];
    }

    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PayoutStatus::Processing,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PayoutStatus::Completed,
            'processed_at' => now(),
        ]);
    }

    public function failed(string $reason = 'Wallet credit refused'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PayoutStatus::Failed,
            'failed_at' => now(),
            'failure_reason' => $reason,
        ]);
    }
}
