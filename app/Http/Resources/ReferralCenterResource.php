<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ReferralCenterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $payload */
        $payload = is_array($this->resource) ? $this->resource : [];

        return [
            'referral_code' => (string) ($payload['referral_code'] ?? ''),
            'referral_url' => (string) ($payload['referral_url'] ?? ''),
            'qr_payload' => (string) ($payload['qr_payload'] ?? ''),
            'commission_rate_percent' => (string) ($payload['commission_rate_percent'] ?? '0.00'),
            'currency' => (string) ($payload['currency'] ?? 'THB'),
            'stats' => $payload['stats'] ?? [],
            'earnings_30d' => $payload['earnings_30d'] ?? [],
            'team' => $payload['team'] ?? [
                'items' => [],
                'pagination' => [
                    'current_page' => 1,
                    'per_page' => 20,
                    'total' => 0,
                    'last_page' => 1,
                ],
            ],
        ];
    }
}
