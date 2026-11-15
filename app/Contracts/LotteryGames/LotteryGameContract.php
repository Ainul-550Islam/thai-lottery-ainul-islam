<?php

declare(strict_types=1);

namespace App\Contracts\LotteryGames;

use App\DTOs\LotteryGames\GameDefinition;
use App\Services\LotteryGames\DigitPrizeMatchingEngine;

/**
 * A registered digit game.
 *
 * THE THIN GAME CLASSES EXIST FOR BINDING, NOT FOR LOGIC
 * Speed88Game and LaoHanoi4DGame implement exactly this interface: an identity,
 * a definition lookup and an engine binding. Every rule they appear to have is
 * read from config/lottery_games.php through GameRegistry, and every decision
 * is made by a shared service. If a game class ever grows a branch that the
 * other does not have, the shared service is missing a concept — the branch
 * belongs there, keyed by configuration, not duplicated per game.
 */
interface LotteryGameContract
{
    /**
     * The stable machine code (config key, draws.game_type, bets.game_code).
     */
    public function code(): string;

    /**
     * The game's current rules, resolved through GameRegistry (DB flags +
     * config), never read directly from config by the caller.
     */
    public function definition(): GameDefinition;

    /**
     * The pure matching engine used for this game's markets. One shared
     * implementation, parameterised by the per-market match mode.
     */
    public function matchingEngine(): DigitPrizeMatchingEngine;
}
