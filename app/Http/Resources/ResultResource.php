<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DrawResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public-Safe Result API Resource.
 *
 * Serializes lottery results without leaking sensitive administrative metadata,
 * internal provider credentials, or unconfirmed settlement facts.
 *
 * @mixin DrawResult
 */
class ResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DrawResult $result */
        $result = $this->resource;

        return [
            'id' => (int) $result->getKey(),
            'draw_id' => (int) $result->draw_id,
            'draw_number' => $result->draw?->draw_number ?? (string) $result->draw_id,
            'first_prize' => (string) $result->first_prize,
            'second_prize' => is_array($result->second_prize) ? array_values(array_map('strval', $result->second_prize)) : [],
            'third_prize' => is_array($result->third_prize) ? array_values(array_map('strval', $result->third_prize)) : [],
            'consolation_prizes' => is_array($result->consolation_prizes) ? array_values(array_map('strval', $result->consolation_prizes)) : [],
            'total_winners' => (int) ($result->total_winners ?? 0),
            'total_payout' => (string) ($result->total_payout ?? '0.00'),
            'published_at' => $result->published_at?->toIso8601String(),
            'winning_numbers' => WinningNumberResource::collection($this->whenLoaded('winningNumbers')),
        ];
    }
}
