<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public shape of "the next draw of a game", plus its countdown.
 *
 * WHY THIS WRAPS AN ARRAY AND NOT A MODEL
 * The array comes from UpcomingDrawsService::summary(), which is the single place that
 * decides WHICH draw is next and how many seconds are left. A resource that rebuilt
 * that from a Draw model would be a second, divergent implementation of the selection
 * rule — and the countdown is the one value a client cannot recompute safely, because
 * it depends on the draw's own betting_close_at and the server's clock.
 *
 * WHAT IS WITHHELD
 * No internal metadata, no settlement state, no operator ids. A player needs to know
 * which draw, when it draws, when betting closes, and how long is left.
 *
 * `seconds_remaining` IS NULL WHEN THERE IS NO NEXT DRAW, AND 0 WHEN IT HAS JUST CLOSED
 * Those are different facts and the client renders them differently, so they are not
 * collapsed into a single falsy value.
 */
final class GameDrawResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $summary  UpcomingDrawsService::summary()
     */
    public function __construct(array $summary)
    {
        parent::__construct($summary);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $summary */
        $summary = $this->resource;

        return [
            'game_code' => $summary['game_code'] ?? null,
            'draw_id' => $summary['draw_id'] ?? null,
            'draw_number' => $summary['draw_number'] ?? null,
            'status' => $summary['status'] ?? null,
            'scheduled_at' => $summary['scheduled_at'] ?? null,
            'betting_close_at' => $summary['betting_close_at'] ?? null,
            'seconds_remaining' => $summary['seconds_remaining'] ?? null,
            'can_accept_bets' => (bool) ($summary['can_accept_bets'] ?? false),
        ];
    }
}
