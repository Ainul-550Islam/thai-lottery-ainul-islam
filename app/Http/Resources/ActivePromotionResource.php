<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One active promotion.
 *
 * `ends_at` is null when the campaign is open-ended — it is NOT filled with a
 * plausible date, because a countdown to an invented deadline is exactly the kind of
 * number Batch 3 forbids showing a player.
 *
 * @property array<string, mixed> $resource
 */
final class ActivePromotionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $promotion */
        $promotion = (array) $this->resource;

        return [
            'type' => (string) ($promotion['type'] ?? 'points_multiplier'),
            'name' => (string) ($promotion['name'] ?? ''),
            'description' => $promotion['description'] ?? null,
            'multiplier' => $promotion['multiplier'] ?? null,
            'multiplier_bps' => $promotion['multiplier_bps'] ?? null,
            'game_scope' => $promotion['game_scope'] ?? null,
            'starts_at' => $promotion['starts_at'] ?? null,
            'ends_at' => $promotion['ends_at'] ?? null,
        ];
    }
}
