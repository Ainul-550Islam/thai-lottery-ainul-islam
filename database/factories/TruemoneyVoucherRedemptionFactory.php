<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TruemoneyRedemptionStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<\App\Models\TruemoneyVoucherRedemption> */
class TruemoneyVoucherRedemptionFactory extends Factory
{
    public function definition(): array
    {
        $voucher = Str::random(48);

        return [
            'user_id' => User::factory(),
            'voucher_hash' => hash('sha256', $voucher),
            'voucher_last4' => substr($voucher, -4),
            'amount' => null,
            'currency' => 'THB',
            'status' => TruemoneyRedemptionStatus::Pending,
            'provider_payload' => [],
            'client_ip' => null,
        ];
    }
}
