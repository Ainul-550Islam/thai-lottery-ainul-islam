<?php

declare(strict_types=1);

namespace App\Jobs\LotteryGames;

use App\Services\LotteryGames\GameAvailabilityService;
use App\Services\LotteryGames\GameRegistry;
use App\Services\LotteryGames\GameResultIngestionService;
use App\Services\LotteryGames\ImportWindowService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fetches and stages official results for one game (or all games).
 *
 * CHUNKED AND RETRY-SAFE
 * One provider call per game per run, and every write is idempotent on
 * (game_code, draw_no, source). A retry after a crash therefore re-reads the
 * same rows instead of duplicating them, which is what makes `tries = 3` safe
 * on a lane that touches money-adjacent tables.
 *
 * IT NEVER PUBLISHES
 * Publication requires a human checker at the existing confirmation boundary.
 * This job can only reach DrawResultIngestionService::ingest(), which stages a
 * pending record. A draw whose result is staged but unconfirmed stays
 * unsettled, which is the intended, visible state.
 *
 * IT ONLY ASKS WHEN A RESULT IS EXPECTED
 * The schedule fires every minute; ImportWindowService answers whether any draw of the
 * game is due and unfinished. Outside that window the game is skipped before any call.
 *
 * IT IS GATED ON AVAILABILITY
 * A game that is not configured (the default for both batch-2 games) is skipped
 * before any network call: polling a feed for a game nobody has approved would
 * generate traffic and operator noise for nothing.
 */
final class ImportGameResultsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 240;

    public function __construct(public readonly ?string $gameCode = null) {}

    public function uniqueId(): string
    {
        return 'lottery-games:import-results:'.($this->gameCode ?? 'all');
    }

    public function handle(
        GameRegistry $registry,
        GameAvailabilityService $availability,
        GameResultIngestionService $ingestion,
        ImportWindowService $window,
    ): void {
        $codes = $this->gameCode === null ? $registry->codes() : [$this->gameCode];

        foreach ($codes as $code) {
            if ($registry->find($code) === null) {
                continue;
            }

            $capability = $availability->capability($code);

            if (! ($capability->gates['official_result_source'] ?? false)) {
                // No official source: do not call a fixture feed to fill the
                // gap, and do not pretend there is nothing to do.
                Log::info('lottery_games.import_skipped', [
                    'game_code' => $code,
                    'reason' => 'official result source not configured',
                ]);

                continue;
            }

            if (! $window->isOpen($code)) {
                // No draw of this game is waiting for a result. Asking the feed anyway
                // would be polling for nothing, every minute, all day.
                Log::debug('lottery_games.import_skipped', [
                    'game_code' => $code,
                    'reason' => 'no draw is awaiting a result',
                ]);

                continue;
            }

            try {
                $result = $ingestion->ingest($code);

                Log::info('lottery_games.import', $result);
            } catch (Throwable $e) {
                Log::warning('lottery_games.import_failed', [
                    'game_code' => $code,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        }
    }
}
