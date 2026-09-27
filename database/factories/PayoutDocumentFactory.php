<?php

namespace Database\Factories;

use App\Enums\Currency;
use App\Enums\PayoutDocumentStatus;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PayoutDocument>
 *
 * completed_at is NOT NULL: a statement only exists for a payout that finished.
 */
class PayoutDocumentFactory extends Factory
{
    public function definition(): array
    {
        $reference = 'PO-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));

        return [
            'document_key' => 'DOC-'.Str::upper(Str::random(12)),
            'statement_number' => 'ST-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
            'payout_id' => Payout::factory()->completed()->state(['reference_number' => $reference]),
            'claimant_user_id' => User::factory(),
            'payout_reference' => $reference,
            'claim_reference' => null,
            'batch_reference' => null,
            'gross_amount' => '7000.00',
            'tax_amount' => '0.00',
            'net_amount' => '7000.00',
            'currency' => Currency::THB,
            'status' => PayoutDocumentStatus::Draft,
            'completed_at' => now(),
        ];
    }

    public function generated(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PayoutDocumentStatus::Generated,
            'generated_at' => now(),
        ]);
    }

    public function issued(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PayoutDocumentStatus::Issued,
            'generated_at' => now()->subMinute(),
            'issued_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PayoutDocumentStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }
}
