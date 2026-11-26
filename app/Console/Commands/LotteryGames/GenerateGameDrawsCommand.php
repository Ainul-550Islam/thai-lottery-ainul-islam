<?php

declare(strict_types=1);

namespace App\Console\Commands\LotteryGames;

use App\Services\LotteryGames\DrawScheduler;
use App\Services\LotteryGames\GameRegistry;
use Illuminate\Console\Command;

/**
 * lottery-games:generate-draws {game?} --days=
 *
 * Creates the upcoming draws a game's configured calendar declares, and opens
 * the ones whose betting window has begun. Idempotent: running it twice on the
 * same day creates nothing the second time.
 */
final class GenerateGameDrawsCommand extends Command
{
    protected $signature = 'lottery-games:generate-draws
                            {game? : A single game code (default: every registered game)}
                            {--days=7 : How many days ahead to materialise}';

    protected $description = 'Materialise upcoming digit-game draws from the configured calendar';

    public function handle(GameRegistry $registry, DrawScheduler $scheduler): int
    {
        $requested = $this->argument('game');
        $codes = is_string($requested) && $requested !== '' ? [$requested] : $registry->codes();

        if ($codes === []) {
            $this->warn('No games are configured in config/lottery_games.php.');

            return self::SUCCESS;
        }

        $days = max(1, (int) $this->option('days'));
        $failures = 0;

        foreach ($codes as $code) {
            $game = $registry->find($code);

            if ($game === null) {
                $this->error("Unknown game: {$code}");
                $failures++;

                continue;
            }

            if ($game->drawCron === [] || $game->cutoffSeconds === null) {
                // The calendar is REQUIRED_INPUT: saying so is more useful than
                // creating a draw at midnight.
                $this->warn("{$code}: draw calendar is not configured (draw_cron / cutoff_seconds). Nothing scheduled.");

                continue;
            }

            $result = $scheduler->generate($game, $days);

            $this->line(sprintf(
                '%s: created %d, existing %d, opened %d, skipped %d',
                $code,
                $result['created'],
                $result['existing'],
                $result['opened'],
                $result['skipped'],
            ));
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
