<?php

declare(strict_types=1);

namespace App\Contracts\LotteryGames;

use App\DTOs\LotteryGames\DigitDrawResult;
use App\DTOs\LotteryGames\GameDefinition;

/**
 * One source of official drawn digits for a digit game.
 *
 * CONTRACT, NOT CONVENIENCE
 *  - Returning null means "this provider does not know", which is ordinary and
 *    lets the chain try the next one.
 *  - Throwing means "this provider is broken or refused", which must be visible
 *    to the caller (the chain records it and may open a circuit breaker). A
 *    provider that swallows its own failure and returns null would turn an
 *    outage into "no result yet" and quietly delay a draw.
 *  - A provider NEVER returns an empty digits string as a success: an empty
 *    result is a schema change or an outage, and is reported as a failure
 *    (LotteryGameException::payloadInvalid) rather than as "not published yet".
 *
 * The interface is deliberately tiny: fetching is the only thing a provider is
 * allowed to do. It cannot write, publish, settle or mutate a draw.
 */
interface GameResultProvider
{
    /**
     * Stable machine name, stored on game_result_imports.source. Part of the
     * import identity, so it must not change once rows exist.
     */
    public function name(): string;

    /**
     * The most recent published result, or null when the provider has none.
     */
    public function fetchLatest(GameDefinition $game): ?DigitDrawResult;

    /**
     * One specific draw by its official draw number, or null when the provider
     * does not carry it.
     */
    public function fetchByDrawNo(GameDefinition $game, string $drawNo): ?DigitDrawResult;
}
