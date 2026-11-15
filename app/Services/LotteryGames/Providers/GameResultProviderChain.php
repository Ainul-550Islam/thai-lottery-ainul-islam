<?php

declare(strict_types=1);

namespace App\Services\LotteryGames\Providers;

use App\Contracts\LotteryGames\GameResultProvider;
use App\DTOs\LotteryGames\DigitDrawResult;
use App\DTOs\LotteryGames\GameDefinition;
use App\Services\Lottery\CircuitBreaker;
use App\Services\LotteryGames\DigitNumberValidator;
use Throwable;

/**
 * Tries each configured provider in order, and records provider health.
 *
 * ORDER MATTERS AND IS CONFIGURATION, NOT CODE
 * The official provider is always first when one is configured; the fixture is
 * only ever reachable outside production. The chain never prefers a later
 * provider because an earlier one was slow — it prefers it only because the
 * earlier one failed, and that failure is recorded against the breaker so the
 * rest of the system can see the provider is unhealthy.
 *
 * BREAKER REUSE
 * The existing App\Services\Lottery\CircuitBreaker owns provider health in this
 * repository (cache-backed, shared across workers). This chain feeds it
 * success/failure per provider name instead of growing a second breaker, so a
 * provider that is failing for the GLO lane is visibly failing here too.
 *
 * A NULL RESULT IS NOT A FAILURE
 * "This provider has no result for that draw yet" is ordinary: it does not trip
 * the breaker, and the chain moves on. A thrown provider is a failure and does
 * trip it.
 */
final class GameResultProviderChain
{
    /**
     * @param  list<GameResultProvider>  $providers
     */
    public function __construct(
        private readonly array $providers,
        private readonly CircuitBreaker $breaker,
        private readonly DigitNumberValidator $validator,
    ) {}

    /**
     * @return list<string>
     */
    public function providerNames(): array
    {
        return array_map(static fn (GameResultProvider $provider): string => $provider->name(), $this->providers);
    }

    public function fetchLatest(GameDefinition $game): ?DigitDrawResult
    {
        return $this->attempt($game, static fn (GameResultProvider $provider): ?DigitDrawResult => $provider->fetchLatest($game));
    }

    public function fetchByDrawNo(GameDefinition $game, string $drawNo): ?DigitDrawResult
    {
        return $this->attempt($game, static fn (GameResultProvider $provider): ?DigitDrawResult => $provider->fetchByDrawNo($game, $drawNo));
    }

    /**
     * @param  callable(GameResultProvider): ?DigitDrawResult  $call
     */
    private function attempt(GameDefinition $game, callable $call): ?DigitDrawResult
    {
        $failures = [];

        foreach ($this->providers as $provider) {
            $name = $provider->name();

            if ($this->breaker->isOpen($name)) {
                $failures[] = $name.': circuit open';

                continue;
            }

            try {
                $result = $call($provider);

                if ($result === null) {
                    // Not a failure: this provider simply has nothing yet.
                    continue;
                }

                // Defence in depth: whatever the provider returned is checked
                // against the game's digit width before it is allowed further.
                $this->validator->validateDrawnDigits($game, $result->digits);

                $this->breaker->recordSuccess($name);

                return $result;
            } catch (Throwable $e) {
                $this->breaker->recordFailure($name, $e->getMessage());
                $failures[] = $name.': '.$e->getMessage();
            }
        }

        if ($failures !== []) {
            report(new \RuntimeException(
                'game_result_provider_chain_exhausted: '.$game->code.' — '.implode(' | ', $failures),
            ));
        }

        return null;
    }
}
