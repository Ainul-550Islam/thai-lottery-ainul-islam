<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public shape of a bet quote: what this selection would cost, what it would pay,
 * and whether it would be accepted right now.
 *
 * WHY A QUOTE IS NOT A PROMISE
 * `potential_payout` is the amount the game's configured multiplier produced at the
 * moment of the quote, using the same PayoutCalculator the placement uses. It is
 * explicitly NOT a guarantee: `seconds_remaining` and `sellable` both exist so a client
 * can see that the offer expires with the draw's betting window, and a placement later
 * re-derives everything under the gate list rather than trusting the quote.
 *
 * `sellable=false` CARRIES `reason`
 * A quote that refused to price anything still answers 200 with a zero payout and a
 * machine reason, so a client can render "not available" without treating an expected
 * condition as an error. The placement route is where the refusal becomes an error.
 */
final class GameBetQuoteResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $quote  GameBetPlacementService::quote()
     */
    public function __construct(array $quote)
    {
        parent::__construct($quote);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $quote */
        $quote = $this->resource;

        return [
            'game_code' => $quote['game_code'] ?? null,
            'bet_type' => $quote['bet_type'] ?? null,
            'number' => $quote['number'] ?? null,
            'stake' => $quote['stake'] ?? null,
            'potential_payout' => $quote['potential_payout'] ?? null,
            'match_mode' => $quote['match_mode'] ?? null,
            'funding_source' => $quote['funding_source'] ?? null,
            'draw_id' => $quote['draw_id'] ?? null,
            'seconds_remaining' => $quote['seconds_remaining'] ?? null,
            'sellable' => (bool) ($quote['sellable'] ?? false),
            'reason' => $quote['reason'] ?? null,
        ];
    }
}
