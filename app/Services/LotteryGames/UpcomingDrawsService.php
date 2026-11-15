<?php

declare(strict_types=1);

namespace App\Services\LotteryGames;

use App\Enums\DrawStatus;
use App\Exceptions\LotteryGameException;
use App\Models\Draw;
use Carbon\CarbonImmutable;

/**
 * "What is the next draw, and how long have I got?" — answered once, for everything
 * that needs to ask.
 *
 * WHY THIS IS A SERVICE AND NOT THREE QUERIES
 * Three different surfaces need the same answer and must not disagree about it:
 *   - the API's game list and place-bet screen, which show the next draw and a
 *     countdown,
 *   - the dashboard, which shows every game's next draw side by side,
 *   - the placement path, which needs to know which draw a bet belongs to.
 * If each of them filtered draws itself, one of them would eventually include a
 * cancelled draw, or a draw whose betting window has passed, and a countdown would
 * say "12 minutes left" for a draw that can no longer be bet on. So the selection
 * rule lives here exactly once.
 *
 * THE SELECTION RULE, STATED
 * The next draw of a game is the earliest draw that
 *   1. belongs to that game (draws.game_type),
 *   2. is not final — a completed or cancelled draw can never be bet on again, and
 *   3. is not already past its betting close time.
 * Among those, the earliest scheduled_at wins. A draw whose status is Scheduled but
 * whose window has closed is therefore skipped rather than shown with a negative
 * countdown: it is a draw the scheduler has not yet closed, not a draw you can join.
 *
 * SECONDS REMAINING IS CLAMPED AT ZERO
 * A countdown that can go negative is a bug in a UI. The value returned is
 * max(0, betting_close_at - now) in whole seconds, and a null is returned when the
 * draw has no close time at all (an unconfigured game, which cannot be sold anyway).
 *
 * READ-ONLY
 * Nothing here creates, opens, closes or mutates a draw. Creating them is
 * DrawScheduler's job and moving them through their states is DrawLifecycleService's.
 */
final class UpcomingDrawsService
{
    public function __construct(private readonly GameRegistry $registry) {}

    /**
     * The next draw of one game, or null when there is none.
     *
     * @throws LotteryGameException game_unknown
     */
    public function nextDraw(string $code): ?Draw
    {
        $candidates = $this->candidateDraws($code);

        foreach ($candidates as $draw) {
            return $draw;
        }

        return null;
    }

    /**
     * Whole seconds until betting closes on the next draw, or null when there is no
     * next draw.
     */
    public function secondsRemaining(string $code): ?int
    {
        $draw = $this->nextDraw($code);

        return $draw instanceof Draw ? $this->secondsRemainingFor($draw) : null;
    }

    /**
     * The next draw of a game as a flat array for the API, the dashboard and the
     * placement response.
     *
     * A single shape everywhere means the mobile client, the web client and the
     * admin screen cannot disagree about what "the next draw" looks like.
     *
     * @return array{
     *     game_code: string,
     *     draw_id: int|null,
     *     draw_number: string|null,
     *     status: string|null,
     *     scheduled_at: string|null,
     *     betting_close_at: string|null,
     *     seconds_remaining: int|null,
     *     can_accept_bets: bool
     * }
     */
    public function summary(string $code): array
    {
        $draw = $this->nextDraw($code);

        if (! $draw instanceof Draw) {
            return [
                'game_code' => $code,
                'draw_id' => null,
                'draw_number' => null,
                'status' => null,
                'scheduled_at' => null,
                'betting_close_at' => null,
                'seconds_remaining' => null,
                'can_accept_bets' => false,
            ];
        }

        return [
            'game_code' => $code,
            'draw_id' => (int) $draw->getKey(),
            'draw_number' => (string) $draw->draw_number,
            'status' => $draw->status->value,
            'scheduled_at' => $draw->scheduled_at?->toIso8601String(),
            'betting_close_at' => $draw->betting_close_at?->toIso8601String(),
            'seconds_remaining' => $this->secondsRemainingFor($draw),
            'can_accept_bets' => $draw->isOpenForBetting(),
        ];
    }

    /**
     * The next draw of every declared game, keyed by game code, in configuration
     * order. This is the dashboard.
     *
     * @return array<string, array<string, mixed>>
     */
    public function summaries(): array
    {
        $summaries = [];

        foreach ($this->registry->codes() as $code) {
            $summaries[$code] = $this->summary($code);
        }

        return $summaries;
    }

    /**
     * The draw a bet placed right now would belong to, or a refusal.
     *
     * The placement path must not resolve this itself: betting into a draw whose
     * window closed between the availability check and the placement would create a
     * bet that cannot be settled with a result it was never eligible for.
     *
     * @throws LotteryGameException game_not_sellable when no draw is accepting bets
     */
    public function drawForPlacement(string $code): Draw
    {
        foreach ($this->candidateDraws($code) as $draw) {
            if ($draw->isOpenForBetting()) {
                return $draw;
            }
        }

        throw LotteryGameException::notSellable(
            'game_no_open_draw',
            ['game_code' => $code, 'gate' => 'seated_open_draw'],
        );
    }

    /**
     * Draws of one game that could still be bet on, earliest first.
     *
     * @return list<Draw>
     *
     * @throws LotteryGameException game_unknown
     */
    private function candidateDraws(string $code): array
    {
        if (! $this->registry->declares($code)) {
            throw LotteryGameException::unknownGame($code);
        }

        $now = CarbonImmutable::now();

        /** @var list<Draw> $draws */
        $draws = Draw::query()
            ->where('game_type', $code)
            // Not final: completed and cancelled draws are history.
            ->whereNotIn('status', [
                DrawStatus::Completed->value,
                DrawStatus::Cancelled->value,
            ])
            // A draw whose window has passed is not "upcoming" even if the scheduler
            // has not closed it yet.
            ->where(function ($query) use ($now): void {
                $query->whereNull('betting_close_at')
                    ->orWhere('betting_close_at', '>=', $now);
            })
            ->orderBy('scheduled_at')
            ->get()
            ->all();

        return $draws;
    }

    /**
     * Seconds remaining for a draw already in hand, clamped at zero.
     */
    private function secondsRemainingFor(Draw $draw): ?int
    {
        $closes = $draw->betting_close_at;

        if (! $closes instanceof \DateTimeInterface) {
            return null;
        }

        return max(0, CarbonImmutable::instance($closes)->getTimestamp() - CarbonImmutable::now()->getTimestamp());
    }
}
