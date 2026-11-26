<?php

declare(strict_types=1);

namespace App\Services\LotteryGames\Providers;

use App\Contracts\LotteryGames\GameResultProvider;
use App\DTOs\LotteryGames\DigitDrawResult;
use App\DTOs\LotteryGames\GameDefinition;
use App\Exceptions\LotteryGameException;

/**
 * A deterministic development result source. NEVER an official one.
 *
 * WHY IT EXISTS
 * Without it, the ingestion, confirmation and settlement lanes of a brand-new
 * game cannot be exercised at all: no official feed is configured (every URL is
 * REQUIRED_INPUT), so every test of those lanes would have to mock HTTP. A
 * fixture provider keeps the seams honest — it produces a well-formed
 * DigitDrawResult from the draw number alone, and it is refused outright in
 * production.
 *
 * THE PRODUCTION REFUSAL IS NOT A FLAG
 * There is no config key that turns this on in production: fetchLatest() and
 * fetchByDrawNo() throw LotteryGameException::fixtureRefusedInProduction on
 * every call when app()->environment('production'), and GameAvailabilityService
 * never counts a fixture as an official result source in any environment. A
 * fixture result can therefore reach a test environment and nothing else.
 *
 * The digits are derived from a hash of the draw number, so repeated calls for
 * the same draw return the same digits — a random fixture would make an
 * idempotency test pass by accident.
 */
final class FixtureGameResultProvider implements GameResultProvider
{
    public function name(): string
    {
        return 'fixture';
    }

    public function fetchLatest(GameDefinition $game): ?DigitDrawResult
    {
        $this->refuseInProduction($game);

        // "Latest" has no meaning for a fixture: the caller must name the draw.
        return null;
    }

    public function fetchByDrawNo(GameDefinition $game, string $drawNo): ?DigitDrawResult
    {
        $this->refuseInProduction($game);

        $digits = $game->resultDigitCount();

        if ($digits === null) {
            throw LotteryGameException::payloadInvalid($game->code, 'the game declares no result digit count');
        }

        // Deterministic digits in [0, 10^digits), zero-padded to the game's
        // width so leading zeros are exercised rather than avoided.
        $seed = hexdec(substr(hash('sha256', $game->code.'|'.$drawNo), 0, 12));
        $modulus = (int) pow(10, $digits);
        $value = str_pad((string) ($seed % $modulus), $digits, '0', STR_PAD_LEFT);

        return new DigitDrawResult(
            gameCode: $game->code,
            drawNo: $drawNo,
            digits: $value,
            drawnAt: now()->toIso8601String(),
            source: $this->name(),
        );
    }

    private function refuseInProduction(GameDefinition $game): void
    {
        if (app()->environment('production')) {
            throw LotteryGameException::fixtureRefusedInProduction($game->code);
        }
    }
}
