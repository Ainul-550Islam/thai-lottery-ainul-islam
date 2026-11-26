<?php

declare(strict_types=1);

namespace Tests\Feature\LotteryGames;

use App\Enums\DrawStatus;
use App\Services\LotteryGames\ImportWindowService;
use Carbon\CarbonImmutable;
use Tests\Support\LotteryGames\GameLaneTestCase;

/**
 * "Within draw windows": the result feed is asked only while a result is expected.
 *
 * Both bounds are configuration (ingestion.import_window_lead_seconds and
 * ingestion.import_window_after_seconds); every case below sets what it needs and asserts
 * the answer, so no default is relied on by accident.
 */
final class ImportWindowTest extends GameLaneTestCase
{
    private function window(): ImportWindowService
    {
        return app(ImportWindowService::class);
    }

    public function test_a_game_with_no_draw_is_never_in_the_window(): void
    {
        $this->assertFalse($this->window()->isOpen('lane_game'));
    }

    public function test_a_draw_that_is_not_yet_due_is_outside_the_window_by_default(): void
    {
        config()->set('lottery_games.ingestion.import_window_lead_seconds', 0);
        config()->set('lottery_games.ingestion.import_window_after_seconds', null);

        $this->openDraw('lane_game', CarbonImmutable::now()->addHour());

        $this->assertFalse($this->window()->isOpen('lane_game'));
    }

    public function test_the_lead_opens_the_window_before_the_draw_is_due(): void
    {
        config()->set('lottery_games.ingestion.import_window_lead_seconds', 7200);
        config()->set('lottery_games.ingestion.import_window_after_seconds', null);

        $this->openDraw('lane_game', CarbonImmutable::now()->addHour());

        $this->assertTrue($this->window()->isOpen('lane_game'));
    }

    public function test_a_due_unfinished_draw_opens_the_window(): void
    {
        config()->set('lottery_games.ingestion.import_window_lead_seconds', 0);
        config()->set('lottery_games.ingestion.import_window_after_seconds', null);

        $this->openDraw('lane_game', CarbonImmutable::now()->subMinute());

        $this->assertTrue($this->window()->isOpen('lane_game'));
    }

    public function test_a_completed_draw_does_not_keep_the_window_open(): void
    {
        config()->set('lottery_games.ingestion.import_window_lead_seconds', 0);
        config()->set('lottery_games.ingestion.import_window_after_seconds', null);

        $draw = $this->openDraw('lane_game', CarbonImmutable::now()->subMinute());
        $draw->forceFill(['status' => DrawStatus::Completed])->save();

        $this->assertFalse($this->window()->isOpen('lane_game'));
    }

    public function test_a_cancelled_draw_does_not_keep_the_window_open(): void
    {
        config()->set('lottery_games.ingestion.import_window_lead_seconds', 0);
        config()->set('lottery_games.ingestion.import_window_after_seconds', null);

        $draw = $this->openDraw('lane_game', CarbonImmutable::now()->subMinute());
        $draw->forceFill(['status' => DrawStatus::Cancelled])->save();

        $this->assertFalse($this->window()->isOpen('lane_game'));
    }

    public function test_the_after_bound_closes_the_window_for_an_old_draw(): void
    {
        config()->set('lottery_games.ingestion.import_window_lead_seconds', 0);
        config()->set('lottery_games.ingestion.import_window_after_seconds', 3600);

        $this->openDraw('lane_game', CarbonImmutable::now()->subHours(3));

        $this->assertFalse($this->window()->isOpen('lane_game'));
    }

    public function test_another_games_draw_does_not_open_this_games_window(): void
    {
        config()->set('lottery_games.ingestion.import_window_lead_seconds', 0);
        config()->set('lottery_games.ingestion.import_window_after_seconds', null);

        $this->openDraw('other_game', CarbonImmutable::now()->subMinute());

        $this->assertFalse($this->window()->isOpen('lane_game'));
    }
}
