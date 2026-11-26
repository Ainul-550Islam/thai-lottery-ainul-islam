<?php

declare(strict_types=1);

namespace App\Services\LotteryGames\Games;

use App\Contracts\LotteryGames\LotteryGameContract;
use App\DTOs\LotteryGames\GameDefinition;
use App\Services\LotteryGames\DigitPrizeMatchingEngine;
use App\Services\LotteryGames\GameRegistry;

/**
 * Speed 88 — a BINDING, not an implementation.
 *
 * Everything this class does is either an identity ('speed_88') or a delegation:
 * rules come from GameRegistry (config + governance), matching comes from the
 * shared DigitPrizeMatchingEngine. There is deliberately no branch here that
 * LaoHanoi4DGame does not also have, because any difference between the two
 * games is configuration, not code.
 */
final class Speed88Game implements LotteryGameContract
{
    public const CODE = 'speed_88';

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
