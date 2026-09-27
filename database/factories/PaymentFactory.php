<?php

namespace Database\Factories;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Payment>
 *
 * (gateway, gateway_reference) is unique, so both stay null until a state sets them.
 */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'reference_number' => 'PAY-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
            'user_id' => User::factory(),
            'payable_type' => null,
            'payable_id' => null,
            'method' => PaymentMethod::Manual,
            'status' => PaymentStatus::Pending,
            'currency' => Currency::THB,
            'amount' => '1000.00',
            'fee' => '0.00',
            'gateway' => null,
            'gateway_reference' => null,
            'gateway_response' => null,
        ];
    }

    public function throughGateway(string $gateway = 'stripe'): static
    {
        return $this->state(fn (array $attributes) => [
            'gateway' => $gateway,
            'gateway_reference' => Str::upper(Str::random(18)),
        ]);
    }

    public function authorized(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Authorized,
            'authorized_at' => now(),
        ]);
    }

    public function captured(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Captured,
            'authorized_at' => now()->subMinute(),
            'captured_at' => now(),
        ]);
    }

    public function failed(string $reason = 'Card declined'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Failed,
            'failed_at' => now(),
            'failure_reason' => $reason,
        ]);
    }
}
