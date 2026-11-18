<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Bet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One bet in the "my bets" list.
 *
 * WHAT IS ABSENT AND WHY
 *   - user_id: the list is already owner-scoped, so the id adds nothing.
 *   - idempotency_key: a derived internal key; returning it would leak its shape.
 *   - raw metadata: internal risk and derivation context.
 *   - the draw's metadata and the ticket's payload: not needed to render a row and
 *     not this endpoint's job to publish.
 *
 * Amounts are the model's decimal:2 strings, forwarded exactly. No float, no
 * number_format, no rounding.
 *
 * @mixin Bet
 */
final class MyBetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Bet $bet */
        $bet = $this->resource;

        return [
            'id' => (int) $bet->getKey(),
            'bet_number' => (string) $bet->bet_number,
            'status' => $bet->status->value,
            'type' => $bet->type?->value,
            'currency' => $bet->currency?->value ?? (string) $bet->currency,
            'stake' => (string) $bet->stake_amount,
            'potential_payout' => (string) $bet->potential_payout,
            'actual_payout' => (string) $bet->actual_payout,
            'is_winning' => $bet->status->isWinning() && bccomp((string) $bet->actual_payout, '0', 2) > 0,
            'draw' => $bet->draw === null ? null : [
                'id' => (int) $bet->draw->id,
                'draw_number' => (string) $bet->draw->draw_number,
                'status' => $bet->draw->status?->value,
                'scheduled_at' => $bet->draw->scheduled_at?->toIso8601String(),
            ],
            'created_at' => $bet->created_at?->toIso8601String(),
        ];
    }
}
