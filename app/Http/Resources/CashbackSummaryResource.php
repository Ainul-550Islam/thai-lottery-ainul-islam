<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CashbackSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $payload */
        $payload = is_array($this->resource) ? $this->resource : [];

        return [
            'currency' => (string) ($payload['currency'] ?? 'THB'),
            'target_wallet' => (string) ($payload['target_wallet'] ?? 'main'),
            'grade' => (string) ($payload['grade'] ?? 'bronze'),
            'base_rate_bps' => (int) ($payload['base_rate_bps'] ?? 0),
            'active_boost_bps' => (int) ($payload['active_boost_bps'] ?? 0),
            'effective_rate_bps' => (int) ($payload['effective_rate_bps'] ?? 0),
            'claimable_amount' => (string) ($payload['claimable_amount'] ?? '0.00'),
            'claimable_count' => (int) ($payload['claimable_count'] ?? 0),
            'min_claim' => (string) ($payload['min_claim'] ?? '0.00'),
            'max_per_period' => (string) ($payload['max_per_period'] ?? '0.00'),
            'can_claim' => (bool) ($payload['can_claim'] ?? false),
            'lifetime_claimed_amount' => (string) ($payload['lifetime_claimed_amount'] ?? '0.00'),
            'next_expiry_at' => $payload['next_expiry_at'] ?? null,
            'recent_accruals' => $payload['recent_accruals'] ?? [],
        ];
    }
}
