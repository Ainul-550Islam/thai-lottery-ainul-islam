<?php

namespace Database\Factories;

use App\Enums\Currency;
use App\Enums\PayoutBatchStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PayoutBatch>
 */
class PayoutBatchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'batch_key' => 'BATCH-'.now()->format('Ymd').'-'.Str::upper(Str::random(10)),
            'status' => PayoutBatchStatus::Pending,
            'currency' => Currency::THB,
            'member_count' => 0,
            'processed_count' => 0,
            'paid_count' => 0,
            'failed_count' => 0,
            'replayed_count' => 0,
            'aggregate_amount' => '0.00',
            'paid_amount' => '0.00',
            'note' => null,
        ];
    }

    public function claimed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PayoutBatchStatus::Processing,
            'claimed_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PayoutBatchStatus::Completed,
            'claimed_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);
    }

    public function failed(string $reason = 'Downstream provider unavailable'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PayoutBatchStatus::Failed,
            'failed_at' => now(),
            'failure_reason' => $reason,
        ]);
    }
}
