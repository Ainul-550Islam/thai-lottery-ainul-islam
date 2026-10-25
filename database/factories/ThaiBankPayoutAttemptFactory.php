<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ThaiBankPayoutState;
use App\Models\Withdrawal;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<\App\Models\ThaiBankPayoutAttempt> */
class ThaiBankPayoutAttemptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'withdrawal_id' => Withdrawal::factory(),
            'withdrawal_reference' => 'WD-'.Str::upper(Str::random(24)),
            'bank' => 'scb',
            'amount' => '1.00',
            'destination_type' => 'bank_account',
            'destination_masked' => '••••1234',
            'state' => ThaiBankPayoutState::Prepared,
            'request_fingerprint' => hash('sha256', Str::uuid()->toString()),
            'outcome_unknown' => false,
            'raw_response' => [],
        ];
    }
}
