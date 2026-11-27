<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Fx\FxRateProvider;
use App\Services\Fx\ManualFxRateProvider;
use App\Services\Fx\NullFxRateProvider;
use App\Services\Loyalty\LoyaltyPointsService;
use App\Services\Loyalty\PointsMultiplierService;
use App\Services\Wallet\WalletOverviewService;
use App\Services\Wallet\WalletService;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Wiring for Batch 3 (player experience), and nothing else.
 *
 * WHY A DEDICATED PROVIDER
 * One readable place that answers "what did this batch bind, and what did it
 * schedule". Registering the FX driver inside AppServiceProvider would put a
 * display concern in the file that every other concern also edits, which is how
 * a money-path provider ends up three hundred lines long and nobody reviews it.
 *
 * THE FX DRIVER IS CHOSEN BY CONFIG, AND THE DEFAULT IS NOTHING
 *   driver = null   -> NullFxRateProvider: no rate, no network, no surprise
 *   driver = manual -> ManualFxRateProvider: the newest admin-entered row
 * An unrecognised driver value falls back to `null` rather than to a provider
 * that guesses: a typo in an env var must not silently enable a network call or a
 * stale rate.
 *
 * CASHBACK IS BOUND LAZILY AND MAY BE MISSING
 * WalletOverviewService accepts a nullable cashback reader. Batch 1 is expected to
 * provide App\Contracts\Wallet\CashbackBalanceReaderInterface; Phase 0 found no
 * such class in this tree, so the binding is attempted by class name and the
 * overview reports `cashback: null, cashback_available: false` when it is absent.
 * That is a visible gap, not a silent zero.
 */
final class PlayerExperienceServiceProvider extends ServiceProvider
{
    /**
     * The contract name Batch 1 would supply, kept as a string so this file does
     * not reference a class that may not exist (a missing class in a `use` is a
     * fatal error at autoload time, not a graceful skip).
     */
    private const CASHBACK_READER_CONTRACT = 'App\\Contracts\\Wallet\\CashbackBalanceReaderInterface';

    public function register(): void
    {
        // The campaign collaborator on LoyaltyPointsService is `?PointsMultiplierService
        // $campaigns = null`. The container fills a nullable parameter that has a
        // default with that default (null) instead of resolving the class, so an
        // auto-wired LoyaltyPointsService never saw an active campaign. Wire it
        // explicitly. With no active campaign PointsMultiplierService is an exact
        // no-op, so earning is unchanged until a campaign runs.
        $this->app->bind(LoyaltyPointsService::class, static fn (Application $app): LoyaltyPointsService => new LoyaltyPointsService(
            $app->make(WalletService::class),
            $app->make(PointsMultiplierService::class),
        ));

        $this->app->singleton(FxRateProvider::class, function (Application $app): FxRateProvider {
            $driver = (string) config('player_experience.fx.driver', 'null');

            return match ($driver) {
                'manual' => new ManualFxRateProvider(),
                default => new NullFxRateProvider(),
            };
        });

        $this->app->bind(WalletOverviewService::class, function (Application $app): WalletOverviewService {
            $reader = null;

            if (class_exists(self::CASHBACK_READER_CONTRACT)) {
                try {
                    $reader = $app->make(self::CASHBACK_READER_CONTRACT);
                } catch (\Throwable) {
                    $reader = null;
                }
            }

            return new WalletOverviewService(
                $app->make(\App\Services\Wallet\WalletBalanceSnapshotService::class),
                $app->make(\App\Services\Fx\FxDisplayService::class),
                is_object($reader) ? $reader : null,
            );
        });
    }

    public function boot(): void
    {
        // The scheduler entries for this batch live in routes/console.php, next
        // to every other scheduled command, so there is one place to read what
        // runs and when. Registering them here as well would be the second
        // schedule source this repository already suffers from.
    }
}
