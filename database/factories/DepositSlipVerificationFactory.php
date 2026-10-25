<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SlipVerificationStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<\App\Models\DepositSlipVerification> */
class DepositSlipVerificationFactory extends Factory
{
    public function definition(): array
    {
        $transRef = 'TEST-'.Str::upper(Str::random(20));

        return [
            'user_id' => User::factory(),
            'trans_ref' => $transRef,
            'amount' => '1.00',
            'currency' => 'THB',
            'provider' => 'easyslip',
            'status' => SlipVerificationStatus::NeedsReview,
            'provider_payload' => [
                'trans_ref_hash' => hash('sha256', $transRef),
                'verification_facts' => [
                    'hard_checks_passed' => true,
                    'provider_valid' => true,
                    'provider_duplicate' => false,
                    'provider_amount' => '1.00',
                    'claimed_amount' => '1.00',
                    'currency' => 'THB',
                    'receiver_matches' => true,
                    'fraud_decision' => 'allow',
                    'trans_ref_hash' => hash('sha256', $transRef),
                ],
            ],
        ];
    }
}
