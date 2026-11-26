<?php

declare(strict_types=1);

namespace App\Services\LotteryGames;

use App\DTOs\LotteryGames\GameDefinition;
use App\Enums\DrawStatus;
use App\Enums\DrawType;
use App\Exceptions\LotteryGameException;
use App\Models\Draw;
use App\Services\Draw\DrawLifecycleService;
use Carbon\CarbonImmutable;
use Cron\CronExpression;

/**
 * Materialises a game's configured draw calendar into real Draw rows.
 *
 * WHY DRAWS ARE ROWS AND NOT COMPUTED ON DEMAND
 * A bet points at a draw. The draw is the settlement boundary: results are published
 * against it, exposure is counted per its id, and settlement walks its bets. So a
 * draw must exist before anybody can bet on it, and it must be stable — regenerating
 * "the next draw" from a cron expression on every request would give two players in
 * the same minute two different draw ids.
 *
 * IDEMPOTENCY IS THE DRAW CODE
 * Every generated draw gets a deterministic draw_code derived from the game and its
 * scheduled instant (SPEED88-20261113-2130). Running the scheduler twice, or running
 * it from two workers at once (the console command and the queue job both exist),
 * therefore produces the same draw_code and the second attempt is a no-op rather
 * than a duplicate draw with duplicate results. draw_code is UNIQUE in the schema,
 * so the database enforces this too, not only the code.
 *
 * THE SCHEDULE COMES FROM CONFIGURATION, VIA THE REGISTRY
 * draw_cron, cutoff_seconds and timezone are operator values read through
 * GameRegistry. Cron parsing uses dragonmantank/cron-expression, which Laravel
 * already depends on — Rule §0.8 forbids ADDING a dependency, not using one the
 * framework ships.
 *
 * A GAME WITH NO SCHEDULE GENERATES NOTHING, LOUDLY
 * If draw_cron or cutoff_seconds is still REQUIRED_INPUT, schedule() raises
 * game_not_configured naming the paths. It does not fall back to "every day at some
 * plausible time": an invented draw time is an invented product, and Rule §0.3
 * forbids it. The consequence is intentional — an unconfigured game has no draws,
 * so gate 7 of GameAvailabilityService can never pass for it, so it cannot be sold.
 *
 * THE LIFECYCLE IS THE EXISTING ONE
 * Draws are created in DrawStatus::Scheduled, which is the state the Batch-1
 * DrawLifecycleService calls Draft. Nothing here writes a status transition: opening
 * and closing draws is that service's job, and this class only creates the future
 * rows it needs to exist.
 *
 * CUTOFF, NOT CLOSE TIME
 * betting_close_at is scheduled_at minus the configured cutoff_seconds. Selling
 * therefore stops before the draw, which is the whole purpose of a cutoff, and the
 * availability gate reads the resulting window from Draw::isOpenForBetting() rather
 * than re-deriving it.
 */
final class DrawScheduler
{
    /**
     * How far ahead the scheduler will create draws in one call.
     *
     * A guard, not a product decision: a daily game asked for 400 draws would create
     * a year of rows and make a typo look like a successful schedule.
     */
    public const MAX_AHEAD = 60;

    public function __construct(
        private readonly GameRegistry $registry,
        private readonly DrawLifecycleService $lifecycle,
    ) {}

    /**
     * Create the next $ahead future draws of one game, skipping any that already
     * exist.
     *
     * @return array{game_code: string, created: list<array<string, mixed>>, skipped: list<string>}
     *
     * @throws LotteryGameException game_unknown|game_not_configured
     */
    public function schedule(string $code, int $ahead = 1, ?CarbonImmutable $from = null): array
    {
        $game = $this->registry->definition($code);
        $this->assertSchedulable($game);

        $ahead = max(1, min(self::MAX_AHEAD, $ahead));
        $from ??= CarbonImmutable::now($this->timezoneFor($game));

        $created = [];
        $skipped = [];

        for ($nth = 0; $nth < $ahead; $nth++) {
            $scheduledAt = $this->nextRun($game, $nth, $from);

            if (! $scheduledAt instanceof CarbonImmutable) {
                continue;
            }

            $drawCode = $this->drawCodeFor($game, $scheduledAt);

            if ($this->drawExists($drawCode)) {
                $skipped[] = $drawCode;

                continue;
            }

            $draw = $this->createDraw($game, $scheduledAt, $drawCode);

            $created[] = [
                'draw_id' => (int) $draw->getKey(),
                'game_code' => $game->code,
                'draw_number' => $draw->draw_number,
                'draw_code' => $draw->draw_code,
                'scheduled_at' => $scheduledAt->toIso8601String(),
                'betting_close_at' => $this->bettingCloseAt($game, $scheduledAt)->toIso8601String(),
                'status' => $draw->status->value,
            ];
        }

        return [
            'game_code' => $game->code,
            'created' => $created,
            'skipped' => $skipped,
        ];
    }

    /**
     * Schedule every declared game, so the command and the job have one entry point.
     *
     * A game that cannot be scheduled does not abort the run: it is reported and the
     * other games still get their draws. That is deliberate — one misconfigured game
     * must not stop the whole calendar — but the report is explicit about it so the
     * silence is visible.
     *
     * @return array{created: list<array<string, mixed>>, skipped: list<string>, refused: list<array{game_code: string, reason: string}>}
     */
    public function scheduleAll(int $ahead = 1, ?CarbonImmutable $from = null): array
    {
        $created = [];
        $skipped = [];
        $refused = [];

        foreach ($this->registry->codes() as $code) {
            try {
                $result = $this->schedule($code, $ahead, $from);
                $created = [...$created, ...$result['created']];
                $skipped = [...$skipped, ...$result['skipped']];
            } catch (LotteryGameException $e) {
                $refused[] = ['game_code' => $code, 'reason' => $e->errorCode()];
            }
        }

        return ['created' => $created, 'skipped' => $skipped, 'refused' => $refused];
    }

    /**
     * The n-th next scheduled instant for a game, in the game's timezone.
     *
     * Returns null when the expression yields nothing (an impossible date, which
     * cron-expression reports by throwing) rather than guessing one.
     */
    public function nextRun(GameDefinition $game, int $nth = 0, ?CarbonImmutable $from = null): ?CarbonImmutable
    {
        $expression = $this->primaryCron($game);

        if ($expression === null) {
            return null;
        }

        try {
            $next = (new CronExpression($expression))->getNextRunDate(
                ($from ?? CarbonImmutable::now($this->timezoneFor($game)))->toDateTimeString(),
                max(0, $nth),
                false,
                $this->timezoneFor($game),
            );
        } catch (\Throwable) {
            return null;
        }

        return CarbonImmutable::instance($next);
    }

    /**
     * The draw_code a generated draw must carry, and therefore its idempotency key.
     *
     * Uppercased, alphanumeric and time-stamped on the game's own clock: an operator
     * reading the draw list can see which game and which instant a draw belongs to
     * without joining anything.
     */
    public function drawCodeFor(GameDefinition $game, CarbonImmutable $scheduledAt): string
    {
        $slug = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', $game->code));

        return $slug.'-'.$scheduledAt->copy()->setTimezone($this->timezoneFor($game))->format('Ymd-Hi');
    }

    /**
     * The instant betting must close for a draw scheduled at $scheduledAt.
     */
    public function bettingCloseAt(GameDefinition $game, CarbonImmutable $scheduledAt): CarbonImmutable
    {
        $cutoff = $game->cutoffSeconds ?? 0;

        return $scheduledAt->subSeconds(max(0, $cutoff));
    }

    /**
     * The draw's own type: the batch-2 lane reuses the existing 2D draw shell.
     *
     * The digit width, markets and payouts are batch-2 configuration; the DRAW is a
     * Batch-1 object and carries one of its existing types. TwoD is the correct shell
     * because it is the only existing type whose number space is a digit string of
     * configurable width, and reusing it is what lets settlement run through the
     * existing orchestrator (Rule §0.2).
     */
    public function drawType(GameDefinition $game): DrawType
    {
        return DrawType::TwoD;
    }

    /**
     * Refuse to schedule a game whose calendar is not fully configured.
     *
     * @throws LotteryGameException game_not_configured
     */
    public function assertSchedulable(GameDefinition $game): void
    {
        $missing = [];

        if ($game->drawCron === []) {
            $missing[] = 'draw_cron';
        }

        foreach ($game->drawCron as $index => $expression) {
            if (str_contains($expression, GameDefinition::REQUIRED_INPUT)) {
                $missing[] = 'draw_cron.'.$index;
            }
        }

        if ($game->cutoffSeconds === null) {
            $missing[] = 'cutoff_seconds';
        }

        if ($missing !== []) {
            throw LotteryGameException::notConfigured(
                $game->code,
                array_values(array_unique($missing)),
                sprintf('Game "%s" has no usable draw calendar, so no draw may be created for it.', $game->code),
            );
        }
    }

    /**
     * Create the row itself.
     *
     * betting_open_at is set to now: the draw exists from the moment the scheduler
     * creates it, and selling is bounded by betting_close_at (the cutoff), not by an
     * opening timestamp nobody configured.
     */
    private function createDraw(GameDefinition $game, CarbonImmutable $scheduledAt, string $drawCode): Draw
    {
        $closeAt = $this->bettingCloseAt($game, $scheduledAt);

        /** @var Draw $draw */
        $draw = Draw::query()->create([
            'draw_number' => $drawCode,
            'draw_code' => $drawCode,
            'type' => $this->drawType($game),
            'game_type' => $game->code,
            'status' => DrawStatus::Scheduled,
            'currency' => (string) config('lottery.currency', 'THB'),
            'scheduled_at' => $scheduledAt->setTimezone($this->timezoneFor($game)),
            'betting_open_at' => CarbonImmutable::now($this->timezoneFor($game)),
            'betting_close_at' => $closeAt,
            'metadata' => [
                'game_code' => $game->code,
                'generated_by' => 'DrawScheduler',
                'cutoff_seconds' => $game->cutoffSeconds,
                'timezone' => $this->timezoneFor($game),
            ],
        ]);

        return $draw;
    }

    private function drawExists(string $drawCode): bool
    {
        return Draw::query()->where('draw_code', $drawCode)->exists();
    }

    /**
     * The first configured cron expression, or null.
     *
     * One expression is used per generated draw series because a draw code must
     * identify exactly one instant; a game with several daily draws declares them as
     * several expressions and the scheduler materialises each series in turn (see
     * schedule() callers).
     */
    private function primaryCron(GameDefinition $game): ?string
    {
        foreach ($game->drawCron as $expression) {
            if (! str_contains($expression, GameDefinition::REQUIRED_INPUT)) {
                return $expression;
            }
        }

        return null;
    }

    private function timezoneFor(GameDefinition $game): string
    {
        return $game->timezone !== '' ? $game->timezone : 'UTC';
    }
}
