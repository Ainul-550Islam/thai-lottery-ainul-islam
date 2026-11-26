<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The wallet overview payload.
 *
 * EVERY AMOUNT IS A STRING, AND EVERY UNKNOWN IS NULL
 *   trend.percent is null when there is no snapshot or the baseline is zero.
 *   fx is null when the feature is off, no rate exists, or the rate is stale.
 *   balances.cashback is null when the Batch-1 reader is not installed.
 *
 * A null is a statement ("not known"); a zero would be a false one. No value here is
 * computed in the resource — the service did the arithmetic in bcmath and this class
 * only forwards it.
 *
 * @property array<string, mixed> $resource
 */
final class WalletOverviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $wallet */
        $wallet = (array) $this->resource;

        return [
            'currency' => (string) ($wallet['currency'] ?? 'THB'),
            'balances' => [
                'main' => (string) ($wallet['balances']['main'] ?? '0.00'),
                'win' => (string) ($wallet['balances']['win'] ?? '0.00'),
                'bonus' => (string) ($wallet['balances']['bonus'] ?? '0.00'),
                'cashback' => $wallet['balances']['cashback'] ?? null,
            ],
            'cashback_available' => (bool) ($wallet['cashback_available'] ?? false),
            'trend' => [
                'days' => (int) ($wallet['trend']['days'] ?? 0),
                'percent' => $wallet['trend']['percent'] ?? null,
                'available' => (bool) ($wallet['trend']['available'] ?? false),
                'baseline' => $wallet['trend']['baseline'] ?? null,
                'baseline_date' => $wallet['trend']['baseline_date'] ?? null,
            ],
            'fx' => $wallet['fx'] ?? null,
            'fx_enabled' => (bool) ($wallet['fx_enabled'] ?? false),
            'generated_at' => $wallet['generated_at'] ?? null,
        ];
    }
}
