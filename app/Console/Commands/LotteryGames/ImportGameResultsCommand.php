<?php

declare(strict_types=1);

namespace App\Console\Commands\LotteryGames;

use App\Services\LotteryGames\GameRegistry;
use App\Services\LotteryGames\GameResultIngestionService;
use Illuminate\Console\Command;
use Throwable;

/**
 * lottery-games:import-results {game?} --draw=
 *
 * Fetches the latest (or one specific) official result and STAGES it through
 * the existing draw result confirmation boundary. It never publishes: a human
 * checker still has to confirm, which is the control the boundary exists for.
 *
 * Exit codes
 *   0  something was staged, or there was simply no result yet
 *   1  a provider or the boundary refused
 */
final class ImportGameResultsCommand extends Command
{
    protected $signature = 'lottery-games:import-results
                            {game? : A single game code (default: every registered game)}
                            {--draw= : Import one specific official draw number}';

    protected $description = 'Import (stage) the official result of a digit game through the existing confirmation boundary';

    public function handle(GameRegistry $registry, GameResultIngestionService $ingestion): int
    {
        $requested = $this->argument('game');
        $codes = is_string($requested) && $requested !== '' ? [$requested] : $registry->codes();
        $drawNo = $this->option('draw');
        $drawNo = is_string($drawNo) && $drawNo !== '' ? $drawNo : null;

        $failures = 0;

        foreach ($codes as $code) {
            if ($registry->find($code) === null) {
                $this->error("Unknown game: {$code}");
                $failures++;

                continue;
            }

            try {
                $result = $ingestion->ingest($code, $drawNo);

                $this->line(sprintf(
                    '%s: status %s (boundary: %s)%s',
                    $code,
                    $result['status'],
                    $result['boundary'],
                    $result['reason'] === null ? '' : ' — '.$result['reason'],
                ));

                if (in_array($result['status'], ['rejected', 'failed'], true)) {
                    $failures++;
                }
            } catch (Throwable $e) {
                $this->error($code.': '.$e->getMessage());
                $failures++;
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
