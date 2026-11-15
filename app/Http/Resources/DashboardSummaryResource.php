<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DashboardSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $payload */
        $payload = is_array($this->resource) ? $this->resource : [];

        return [
            'member_id' => (int) ($payload['member_id'] ?? 0),
            'currency' => (string) ($payload['currency'] ?? 'THB'),
            'wallets' => $payload['wallets'] ?? [],
            'winnings' => $payload['winnings'] ?? [],
            'bets' => $payload['bets'] ?? [],
            'loyalty' => $payload['loyalty'] ?? [],
            'vip' => $payload['vip'] ?? [],
            'cashback' => $payload['cashback'] ?? [],
            'free_bet_vouchers' => $payload['free_bet_vouchers'] ?? [],
            'referrals' => $payload['referrals'] ?? [],
            'recent_activity' => $payload['recent_activity'] ?? [],
            'upcoming_draws' => $payload['upcoming_draws'] ?? [],
            'security' => $payload['security'] ?? [],
            'next_draw' => $payload['next_draw'] ?? [],
            'generated_at' => $payload['generated_at'] ?? null,
        ];
    }
}
