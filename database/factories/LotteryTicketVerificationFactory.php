<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LotteryTicketVerification;
use App\Services\Lottery\PrizeVerificationService;
use App\Services\Lottery\TicketAuthenticityService;
use App\Services\Lottery\TicketIdentityService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LotteryTicketVerification>
 *
 * Evidence of one public verification (PROMPT 4).
 *
 * NOTE THE ABSENCE. There is no attribute here holding a ticket number or a
 * barcode payload, because the table has none: query_fingerprint is a keyed
 * hash and the raw query is never stored. A factory that invented such a
 * column would not persist - which is exactly the guarantee wanted.
 */
class LotteryTicketVerificationFactory extends Factory
{
    protected $model = LotteryTicketVerification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'verification_kind' => TicketIdentityService::KIND_NUMBER,
            'product' => TicketIdentityService::PRODUCT_GLO_L6,
            // A hash, never a lottery number.
            'query_fingerprint' => hash('sha256', (string) Str::uuid()),
            'public_status' => PrizeVerificationService::FOUND_NOT_WINNING,
            'authenticity_state' => TicketAuthenticityService::NOT_VERIFIED,
            'barcode_state' => null,
            'fixture_used' => false,
            'policy_version' => '1',
            'correlation_id' => null,
            'metadata' => [],
            'verified_at' => now(),
        ];
    }

    public function winning(): static
    {
        return $this->state(fn (array $attributes): array => [
            'public_status' => PrizeVerificationService::WINNING,
            'authenticity_state' => TicketAuthenticityService::PUBLIC_RECORD_FOUND,
        ]);
    }

    public function fixture(): static
    {
        return $this->state(fn (array $attributes): array => [
            'fixture_used' => true,
            'barcode_state' => 'SUPPORTED',
        ]);
    }
}
