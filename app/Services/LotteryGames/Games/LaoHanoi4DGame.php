<?php

declare(strict_types=1);

namespace App\Services\LotteryGames\Games;

use App\Contracts\LotteryGames\LotteryGameContract;
use App\DTOs\LotteryGames\GameDefinition;
use App\Services\LotteryGames\DigitPrizeMatchingEngine;
use App\Services\LotteryGames\GameRegistry;

/**
 * Lao Hanoi 4D — a BINDING, not an implementation.
 *
 * Identical in shape to Speed88Game by design; see that class for why the
 * absence of per-game logic is the point.
 */
final class LaoHanoi4DGame implements LotteryGameContract
{
    public const CODE = 'lao_hanoi_4d';

    public function __construct(
        private readonly GameRegistry $registry,
        private readonly DigitPrizeMatchingEngine $engine,
    ) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function definition(): GameDefinition
    {
        return $this->registry->definition(self::CODE);
    }

    public function matchingEngine(): DigitPrizeMatchingEngine
    {
        return $this->engine;
    }
}
