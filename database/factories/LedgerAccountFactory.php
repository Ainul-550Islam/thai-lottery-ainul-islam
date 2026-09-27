<?php

namespace Database\Factories;

use App\Enums\Currency;
use App\Enums\LedgerAccountType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\LedgerAccount>
 *
 * The real chart of accounts is seeded by Database\Seeders\LedgerAccountSeeder.
 * This factory exists for tests that need an arbitrary extra account.
 */
class LedgerAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'ACC-'.Str::upper(Str::random(6)),
            'name' => 'Factory account',
            'type' => LedgerAccountType::Asset,
            'currency' => Currency::THB,
            'description' => null,
            'is_active' => true,
            'parent_account_id' => null,
            'opening_balance' => '0.00',
            'current_balance' => '0.00',
        ];
    }

    public function ofType(LedgerAccountType $type): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => $type,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
