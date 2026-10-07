<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Currency;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Transaction> */
final class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'reference_number' => 'TX-'.Str::upper(Str::random(16)),
            'user_id' => User::factory(),
            'wallet_id' => Wallet::factory(),
            'type' => TransactionType::Deposit,
            'status' => TransactionStatus::Pending,
            'currency' => Currency::THB,
            'amount' => '1000.00',
            'fee' => '0.00',
            'description' => 'Factory-made transaction',
            'idempotency_key' => (string) Str::uuid(),
        ];
    }
}
