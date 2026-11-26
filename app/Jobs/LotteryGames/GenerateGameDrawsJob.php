<?php

declare(strict_types=1);

namespace App\Jobs\LotteryGames;

use App\Services\LotteryGames\DrawScheduler;
use App\Services\LotteryGames\GameRegistry;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Materialises upcoming draws for one game (or every game) off the request
 * path.
 *
 * UNIQUE PER CADENCE
 * ShouldBeUnique keyed on the game + day means the daily schedule cannot stack
 * two overlapping generation runs; combined with DrawScheduler's deterministic
 * draw numbers, even a duplicate run converges on the same rows instead of
 * creating twins.
 */
final class GenerateGameDrawsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly ?string $gameCode = null,
        public readonly int $days = 7,
    ) {}

    public function uniqueId(): string
    {
        return 'lottery-games:generate-draws:'.($this->gameCode ?? 'all').':'.now()->toDateString();
    }

    public function handle(GameRegistry $registry, DrawScheduler $scheduler): void
    {
        $codes = $this->gameCode === null ? $registry->codes() : [$this->gameCode];

        foreach ($codes as $code) {
            $game = $registry->find($code);

            if ($game === null) {
                Log::warning('lottery_games.generate_draws_unknown_game', ['game_code' => $code]);

                continue;
            }

            $result = $scheduler->generate($game, $this->days);

            Log::info('lottery_games.generate_draws', $result);
        }
    }
}
