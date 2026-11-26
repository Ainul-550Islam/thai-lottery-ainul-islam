<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Lottery\CircuitBreaker;
use App\Services\LotteryGames\DigitNumberValidator;
use App\Services\LotteryGames\GameRegistry;
use App\Services\LotteryGames\Providers\FixtureGameResultProvider;
use App\Services\LotteryGames\Providers\GameResultProviderChain;
use App\Services\LotteryGames\Providers\OfficialGameResultProvider;
use App\Services\LotteryGames\RulesProfileResolver;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Wiring for the batch-2 lane. Bindings only — no logic, no rules, no side effects.
 *
 * WHY A PROVIDER IS NEEDED AT ALL, GIVEN AUTOWIRING
 * Laravel resolves almost every class in this lane on its own: the services take
 * interfaces and concrete classes that the container can build. Two things it cannot
 * build are:
 *
 *   1  GameRegistry must be a SINGLETON. It memoises definitions and governance rows
 *      per request, and the whole point of that memoisation is that the checksum an
 *      operator verified, the checksum a bets is priced against and the checksum the
 *      availability gate compares are THE SAME VALUE within one request. Two instances
 *      would each read the database (and could straddle a concurrent operator action),
 *      and the test helper
 *      (tests/Feature/LotteryGames/Concerns/BuildsDigitGame) already relies on
 *      app()->forgetInstance(GameRegistry::class) behaving like a singleton.
 *
 *   2  GameResultProviderChain takes a LIST of providers, which the container cannot
 *      invent. The order is a policy — official first, fixture last and only outside
 *      production — so it is declared here, once, in code rather than guessed.
 *
 * WHY THE PROVIDER DOES NOT TOUCH THE DATABASE OR THE CONFIGURATION
 * A service provider's register()/boot() runs for EVERY request, including artisan
 * commands and health checks. Reading tables here would make the whole application
 * depend on the batch-2 migrations having run, which would break a deployment that is
 * mid-migrate. The lane's own "is anything configured" questions are answered lazily,
 * by the services, when they are actually asked.
 *
 * THE FIXTURE PROVIDER IS NOT MERELY NOT-FIRST, IT IS ABSENT IN PRODUCTION
 * It refuses every call in production on its own (see FixtureGameResultProvider), and
 * it is left out of the chain there as well, so a production process cannot even name
 * it as a candidate.
 */
final class LotteryGamesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            GameRegistry::class,
            static fn (Application $app): GameRegistry => new GameRegistry($app['config']),
        );

        $this->app->singleton(GameResultProviderChain::class, function (Application $app): GameResultProviderChain {
            $validator = $app->make(DigitNumberValidator::class);

            $providers = [
                // Official first: the only source that can satisfy the availability
                // gate, and the only one whose failure should trip the breaker first.
                new OfficialGameResultProvider($app['config'], $validator),
            ];

            if (! $app->environment('production')) {
                // Development aid. Same object the container would build; named
                // explicitly so the ordering is visible here rather than inferred.
                $providers[] = $app->make(FixtureGameResultProvider::class);
            }

            return new GameResultProviderChain(
                $providers,
                $app->make(CircuitBreaker::class),
                $validator,
            );
        });
    }

    public function boot(): void
    {
        // THE ONLY THING BOOT DOES: honour a named rules layer.
        //
        // Nothing here touches the database, and nothing here decides a rule. It asks
        // RulesProfileResolver which rule set is in force — declared config, an
        // optional non-production profile, an optional operator override — and writes
        // the answer back into the config repository so every existing reader
        // (GameRegistry is the only one) sees the same block it always saw. With no
        // profile named and no override file present, which is the default and the only
        // production shape, this call changes nothing at all.
        //
        // It is in boot() rather than register() because Log is available here; the
        // resolver's only side effect is a log line naming the layers it applied.
        $this->app->make(RulesProfileResolver::class)->applyToRuntimeConfig();
    }
}
