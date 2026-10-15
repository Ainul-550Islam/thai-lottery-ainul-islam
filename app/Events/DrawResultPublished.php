<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Draw;
use App\Models\DrawResult;
use App\Services\Lottery\GloResultTrustPolicy;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when official draw results are published.
 */
final class DrawResultPublished implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly Draw $draw,
        public readonly DrawResult $result,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('draws'),
            new Channel('draw.'.$this->draw->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'draw.result.published';
    }

    /** Prevent legacy or untrusted result rows from reaching any player channel. */
    public function broadcastWhen(): bool
    {
        return app(GloResultTrustPolicy::class)->isBroadcastable($this->result, $this->draw);
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $publication = app(GloResultTrustPolicy::class)
            ->trustedPublication($this->result, $this->draw);

        if ($publication === null) {
            return [];
        }

        $draw = $publication['draw'];
        $result = $publication['result'];
        $result->loadMissing('winningNumbers');

        return [
            'draw_id' => (int) $draw->id,
            'draw_number' => (string) $draw->draw_number,
            'first_prize' => (string) $result->first_prize,
            'second_prize' => $result->second_prize,
            'third_prize' => $result->third_prize,
            'two_digit_bottom' => $result->metadata['bottom_two'] ?? $result->metadata['two_digit_bottom'] ?? null,
            'total_winners' => (int) $result->total_winners,
            'total_payout' => (string) $result->total_payout,
            'published_at' => $result->published_at?->toIso8601String(),
            'winning_numbers' => $result->winningNumbers->map(fn ($wn): array => [
                'bet_type' => $wn->bet_type?->value,
                'number' => (string) $wn->number,
                'prize_tier' => $wn->prize_tier,
                'position' => $wn->position,
                'payout_multiplier' => (int) $wn->payout_multiplier,
            ])->values()->all(),
        ];
    }
}
