<?php

declare(strict_types=1);

namespace App\Services\LotteryGames;

use App\Enums\DrawStatus;
use App\Models\Draw;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Is it worth asking the result feed about this game right now?
 *
 * WHY THE SCHEDULE ALONE IS NOT THE ANSWER
 * ImportGameResultsJob is registered to run every minute, which is the right polling
 * rhythm for the moments a result is expected and a waste of a feed's goodwill - and of
 * operator log noise - for the other twenty-three hours. The question "is a result
 * expected?" is not a clock question, it is a draw question: a result is expected for a
 * draw of this game that has reached its scheduled time and has not been finished or
 * cancelled. So the window is derived from the draws themselves, not from a second
 * copy of the draw schedule that could drift from the first.
 *
 * WHAT COUNTS AS "IN THE WINDOW"
 * A draw of the game (draws.game_type = the game code) that is neither Completed nor
 * Cancelled and whose scheduled_at is no later than now plus the lead. A draw whose
 * result is staged but not yet published is still unfinished, so polling continues
 * until the existing confirmation boundary completes it; staging is idempotent on
 * (game, draw number, source), so the repeated polls write nothing new.
 *
 * TWO KNOBS, BOTH CONFIGURATION, NEITHER INVENTED HERE
 *   ingestion.import_window_lead_seconds   start asking this long before scheduled_at
 *                                          (default 0: not before the draw is due)
 *   ingestion.import_window_after_seconds  stop asking for a draw this long after
 *                                          scheduled_at (default NULL: keep asking until
 *                                          the draw is finished or cancelled)
 * Neither default encodes an official fact about any game, which is the point: how
 * early a feed publishes, and how long an operator wants to keep polling, are operator
 * decisions.
 */
final class ImportWindowService
{
    public function __construct(private readonly ConfigRepository $config) {}

    public function isOpen(string $gameCode, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        $lead = max(0, (int) $this->config->get('lottery_games.ingestion.import_window_lead_seconds', 0));
        $after = $this->config->get('lottery_games.ingestion.import_window_after_seconds');

        $query = Draw::query()
            ->where('game_type', $gameCode)
            ->whereNotIn('status', [
                DrawStatus::Completed->value,
                DrawStatus::Cancelled->value,
            ])
            ->where('scheduled_at', '<=', $now->addSeconds($lead));

        if ($after !== null && $after !== '') {
            $query->where('scheduled_at', '>=', $now->subSeconds(max(0, (int) $after)));
        }

        return $query->exists();
    }
}
