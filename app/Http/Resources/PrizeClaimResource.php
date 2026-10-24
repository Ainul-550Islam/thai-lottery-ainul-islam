<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Payout;
use App\Services\Prize\ClaimWindowService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Payout
 */
final class PrizeClaimResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $record = $this->resource;

        if (is_array($record)) {
            return [
                'bet_id' => (int) ($record['bet_id'] ?? 0),
                'payout_id' => isset($record['payout_id']) ? (int) $record['payout_id'] : null,
                'status' => isset($record['status']) ? (string) $record['status'] : null,
                'amount' => isset($record['amount']) ? (string) $record['amount'] : null,
                'currency' => isset($record['currency']) ? (string) $record['currency'] : null,
                'window_closes_at' => isset($record['window_closes_at']) ? (string) $record['window_closes_at'] : null,
                'claimed_at' => isset($record['claimed_at']) ? (string) $record['claimed_at'] : null,
                'no_op' => (bool) ($record['no_op'] ?? false),
                'payout_reference' => isset($record['payout_reference']) ? (string) $record['payout_reference'] : null,
                'payout_status' => isset($record['payout_status']) ? (string) $record['payout_status'] : null,
                'window_open' => isset($record['window_open']) ? (bool) $record['window_open'] : false,
            ];
        }

        /** @var Payout $payout */
        $payout = $record;

        $metadata = is_array($payout->metadata) ? $payout->metadata : [];
        $claim = is_array($metadata['claim'] ?? null) ? $metadata['claim'] : [];
        $window = is_array($metadata['claim_window'] ?? null) ? $metadata['claim_window'] : [];

        return [
            'bet_id' => $payout->bet_id !== null ? (int) $payout->bet_id : null,
            'payout_id' => (int) $payout->getKey(),
            'status' => isset($claim['status']) ? (string) $claim['status'] : null,
            'amount' => (string) $payout->amount,
            'currency' => $payout->currency?->value,
            'window_closes_at' => isset($claim['window_closes_at'])
                ? (string) $claim['window_closes_at']
                : (isset($window['closes_at']) ? (string) $window['closes_at'] : null),
            'claimed_at' => isset($claim['claimed_at']) ? (string) $claim['claimed_at'] : null,
            'no_op' => false,
            'payout_reference' => (string) $payout->reference_number,
            'payout_status' => $payout->status?->value,
            'window_open' => app(ClaimWindowService::class)->allowsClaim($payout),
        ];
    }
}
