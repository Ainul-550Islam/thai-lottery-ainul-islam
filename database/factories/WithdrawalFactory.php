<?php

namespace Database\Factories;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Enums\WithdrawalStatus;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Withdrawal>
 *
 * payout_details is an ENCRYPTED array cast — bank details never sit in plaintext.
 */
class WithdrawalFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'reference_number' => 'WD-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
            'user_id' => User::factory(),
            'wallet_id' => Wallet::factory(),
            'financial_transaction_id' => null,
            'method' => PaymentMethod::BankTransfer,
            'provider' => null,
            'provider_reference' => null,
            'status' => WithdrawalStatus::Pending,
            'currency' => Currency::THB,
            'amount' => '1000.00',
            'fee' => '0.00',
            'net_amount' => '1000.00',
            'idempotency_key' => (string) Str::uuid(),
            'payout_details' => ['bank' => 'Bangkok Bank', 'account_last4' => '4321'],
            'requested_at' => now(),
        ];
    }

    public function underReview(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WithdrawalStatus::UnderReview,
            'reviewed_at' => now(),
        ]);
    }

    public function kycRequired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WithdrawalStatus::KycRequired,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WithdrawalStatus::Approved,
            'approved_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WithdrawalStatus::Completed,
            'approved_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);
    }

    public function rejected(string $reason = 'Failed compliance review'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WithdrawalStatus::Rejected,
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);
    }
}
