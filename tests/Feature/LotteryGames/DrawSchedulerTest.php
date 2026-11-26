<?php

declare(strict_types=1);

namespace Tests\Feature\LotteryGames;

use App\Enums\DrawStatus;
use App\Enums\DrawType;
use App\Exceptions\LotteryGameException;
use App\Models\Draw;
use App\Services\LotteryGames\DrawScheduler;
use Carbon\CarbonImmutable;
use Tests\Support\LotteryGames\GameLaneTestCase;

/**
 * The draw calendar: creating the rows a digit game sells into.
 *
 * WHY THIS SUITE MATTERS MORE THAN A CALENDAR USUALLY WOULD
 * A digit game cannot sell without an open draw (that is gate 7), so this scheduler is
 * the component that turns "configured" into "tradeable". It is also the one component
 * that runs unattended, every night, and writes rows into the same table Batch 1 uses for
 * its own draws. Three properties therefore have to hold, and each is tested directly:
 *
 *   1. IDEMPOTENT. Running it twice must not create a second draw for the same instant.
 *      Duplicate draws would split betting liquidity across two rows while both claim to
 *      be "the" 21:00 draw. The draw_code is the idempotency key, so a re-run skips
 *      rather than duplicates.
 *
 *   2. HONEST ABOUT TIME. The schedule is expressed in the GAME's timezone, the cutoff is
 *      a real window before the draw, and the generated code is stamped on the game's own
 *      clock. A draw labelled 21:00 that closes at 21:00 in a different timezone is a
 *      draw that closes before it opens - the exact defect the rules validator refuses.
 *
 *   3. FAIL-CLOSED. A game whose calendar or cutoff is still REQUIRED_INPUT produces no
 *      rows and says why. An unconfigured game must never acquire a draw, because a draw
 *      is what makes it sellable.
 *
 * WHAT IS NOT TESTED HERE
 * Whether the created draw is then opened, closed and settled. DrawLifecycleService owns
 * those transitions and is Batch 1's tested component; this suite asserts only that the
 * row it creates is the shape that service expects (open window, cutoff before the draw,
 * Scheduled status) and that Batch 1's own draws are left alone.
 */
final class DrawSchedulerTest extends GameLaneTestCase
{
    private function scheduler(): DrawScheduler
    {
        return app(DrawScheduler::class);
    }

    /**
     * A fixed instant in the game's timezone, so the assertions are about arithmetic and
     * not about what time the CI machine happens to be at.
     */
    private function lookFrom(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-03-10 08:00:00', 'Asia/Bangkok');
    }

    // =========================================================================
    // Creating draws
    // =========================================================================

    public function test_it_creates_the_next_draw_of_a_configured_game(): void
    {
        $this->declareGame('lane_game', $this->laneGameConfig('lane_game'));

        $result = $this->scheduler()->schedule('lane_game', 1, $this->lookFrom());

        $this->assertSame('lane_game', $result['game_code']);
        $this->assertCount(1, $result['created']);
        $this->assertSame([], $result['skipped']);

        $created = $result['created'][0];
        $this->assertSame('lane_game', $created['game_code']);
        $this->assertSame('scheduled', $created['status']);
        // 21:00 on the game's clock, on the day the scheduler was told to look from.
        $this->assertSame('2026-03-10T21:00:00+07:00', $created['scheduled_at']);
        // The configured cutoff is five minutes, so betting closes at 20:55.
        $this->assertSame('2026-03-10T20:55:00+07:00', $created['betting_close_at']);

        $draw = Draw::query()->findOrFail($created['draw_id']);

        $this->assertSame('lane_game', $draw->game_type);
        $this->assertSame(DrawType::TwoD, $draw->type, 'a digit game reuses the existing 2D draw shell');
        $this->assertSame(DrawStatus::Scheduled, $draw->status);
        $this->assertSame('DrawScheduler', $draw->metadata['generated_by']);
    }

    public function test_it_creates_several_draws_ahead_in_chronological_order(): void
    {
        $this->declareGame('lane_game', $this->laneGameConfig('lane_game'));

        $result = $this->scheduler()->schedule('lane_game', 3, $this->lookFrom());

        $this->assertCount(3, $result['created']);

        $instants = array_column($result['created'], 'scheduled_at');
        $sorted = $instants;
        sort($sorted);

        $this->assertSame($sorted, $instants, 'draws must be created in the order they run');
        $this->assertSame(
            ['2026-03-10T21:00:00+07:00', '2026-03-11T21:00:00+07:00', '2026-03-12T21:00:00+07:00'],
            $instants,
        );
    }

    public function test_running_it_twice_does_not_duplicate_a_draw(): void
    {
        $this->declareGame('lane_game', $this->laneGameConfig('lane_game'));

        $first = $this->scheduler()->schedule('lane_game', 1, $this->lookFrom());
        $second = $this->scheduler()->schedule('lane_game', 1, $this->lookFrom());

        $this->assertCount(1, $first['created']);
        $this->assertSame([], $second['created'], 'the second run must create nothing');
        $this->assertSame([$first['created'][0]['draw_code']], $second['skipped']);

        $this->assertSame(1, Draw::query()->where('game_type', 'lane_game')->count());
    }

    public function test_the_draw_code_is_stamped_on_the_games_own_clock_and_carries_the_game(): void
    {
        $this->declareGame('lane_game', $this->laneGameConfig('lane_game'));

        $result = $this->scheduler()->schedule('lane_game', 1, $this->lookFrom());
        $code = $result['created'][0]['draw_code'];

        $this->assertSame('LANEGAME-20260310-2100', $code);
        $this->assertSame($code, $result['created'][0]['draw_number'], 'the draw_number is the same human key');
    }

    public function test_a_second_daily_expression_is_not_materialised_by_one_call(): void
    {
        // The scheduler materialises the PRIMARY expression; a game with several daily
        // draws is caught up by repeated runs, not by inventing one draw per expression
        // under a single code.
        $this->declareGame('lane_game', $this->laneGameConfig('lane_game', [
            'draw_cron' => ['0 21 * * *', '0 12 * * *'],
        ]));

        $result = $this->scheduler()->schedule('lane_game', 1, $this->lookFrom());

        $this->assertCount(1, $result['created']);
        $this->assertSame('2026-03-10T21:00:00+07:00', $result['created'][0]['scheduled_at']);
    }

    // =========================================================================
    // Fail-closed
    // =========================================================================

    public function test_a_game_whose_calendar_is_still_required_input_creates_nothing(): void
    {
        $this->declareGame('lane_game', $this->laneGameConfig('lane_game', [
            'draw_cron' => ['REQUIRED_INPUT'],
        ]));

        try {
            $this->scheduler()->schedule('lane_game', 1, $this->lookFrom());
            $this->fail('a game with no calendar must not acquire a draw');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_not_configured', $e->errorCode());
            $this->assertContains('draw_cron.0', $e->context()['missing'] ?? []);
        }

        $this->assertSame(0, Draw::query()->where('game_type', 'lane_game')->count());
    }

    public function test_a_game_whose_cutoff_is_still_required_input_creates_nothing(): void
    {
        $this->declareGame('lane_game', $this->laneGameConfig('lane_game', [
            'cutoff_seconds' => 'REQUIRED_INPUT',
        ]));

        $this->expectException(LotteryGameException::class);

        try {
            $this->scheduler()->schedule('lane_game', 1, $this->lookFrom());
        } finally {
            $this->assertSame(0, Draw::query()->where('game_type', 'lane_game')->count());
        }
    }

    public function test_scheduling_an_undeclared_game_is_refused_as_unknown(): void
    {
        try {
            $this->scheduler()->schedule('no_such_game', 1, $this->lookFrom());
            $this->fail('an unknown game cannot be scheduled');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_unknown', $e->errorCode());
        }
    }

    public function test_the_shipped_unconfigured_games_are_refused_one_by_one_without_stopping_the_run(): void
    {
        // One good game next to the two shipped placeholders: the run must create the
        // good game's draw and report the other two, not abort.
        $this->declareGame('lane_game', $this->laneGameConfig('lane_game'));

        $result = $this->scheduler()->scheduleAll(1, $this->lookFrom());

        $this->assertCount(1, $result['created']);
        $this->assertSame('lane_game', $result['created'][0]['game_code']);

        $refusedCodes = array_column($result['refused'], 'game_code');
        sort($refusedCodes);
        $this->assertSame(['lao_hanoi_4d', 'speed_88'], $refusedCodes);

        foreach ($result['refused'] as $refusal) {
            $this->assertSame('game_not_configured', $refusal['reason']);
        }
    }

    public function test_the_ahead_count_is_clamped_so_a_typo_cannot_create_a_year_of_draws(): void
    {
        $this->declareGame('lane_game', $this->laneGameConfig('lane_game'));

        $result = $this->scheduler()->schedule('lane_game', 10000, $this->lookFrom());

        $this->assertCount(DrawScheduler::MAX_AHEAD, $result['created']);
    }

    // =========================================================================
    // The calendar arithmetic
    // =========================================================================

    public function test_the_cutoff_is_subtracted_from_the_draw_instant(): void
    {
        $definition = $this->declareGame('lane_game', $this->laneGameConfig('lane_game', [
            'cutoff_seconds' => 900,
        ]));

        $drawAt = CarbonImmutable::parse('2026-03-10 21:00:00', 'Asia/Bangkok');

        $this->assertSame(
            '2026-03-10 20:45:00',
            $this->scheduler()->bettingCloseAt($definition, $drawAt)->format('Y-m-d H:i:s'),
        );
    }

    public function test_the_next_run_is_expressed_in_the_games_timezone(): void
    {
        $definition = $this->declareGame('lane_game', $this->laneGameConfig('lane_game'));

        $next = $this->scheduler()->nextRun($definition, 0, $this->lookFrom());

        $this->assertNotNull($next);
        $this->assertSame('Asia/Bangkok', $next->getTimezone()->getName());
        $this->assertSame('21:00', $next->format('H:i'));
    }

    public function test_an_unparseable_expression_yields_no_instant_rather_than_a_guess(): void
    {
        $definition = $this->declareGame('lane_game', $this->laneGameConfig('lane_game', [
            'draw_cron' => ['every evening please'],
        ]));

        $this->assertNull($this->scheduler()->nextRun($definition, 0, $this->lookFrom()));
    }
}
