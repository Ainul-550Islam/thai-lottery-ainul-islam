<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\Cashback\AccrueCashbackCommand;
use App\Console\Commands\Security\PruneLoginEventsCommand;
use App\Enums\BetStatus;
use App\Enums\LoginEventOutcome;
use App\Enums\MfaChallengeStatus;
use App\Events\MfaChallengeVerified;
use App\Events\Security\NewDeviceLoginDetected;
use App\Listeners\Security\NotifyOnNewDeviceLogin;
use App\Listeners\Security\RecordLoginEvent;
use App\Jobs\Rewards\ExpireRewardArtifactsJob;
use App\Models\Bet;
use App\Models\MemberBankAccount;
use App\Models\MfaChallenge;
use App\Models\RewardClaim;
use App\Models\User;
use App\Policies\MemberBankAccountPolicy;
use App\Policies\RewardClaimPolicy;
use App\Services\Cashback\CashbackAccrualService;
use App\Services\Rewards\Fulfillers\BonusCashFulfiller;
use App\Services\Rewards\Fulfillers\CashbackBoostFulfiller;
use App\Services\Rewards\Fulfillers\FreeBetFulfiller;
use App\Services\Rewards\Fulfillers\VipUpgradeFulfiller;
use App\Services\Rewards\RewardFulfillerRegistry;
use App\Services\Security\LoginHistory\GeoIp\GeoIpResolver;
use App\Services\Security\LoginHistory\GeoIp\GeoIpResolverManager;
use App\Services\Security\LoginHistory\LoginEventRecorder;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class RewardsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GeoIpResolver::class, GeoIpResolverManager::class);

        $this->app->singleton(BonusCashFulfiller::class);
        $this->app->singleton(CashbackBoostFulfiller::class);
        $this->app->singleton(FreeBetFulfiller::class);
        $this->app->singleton(VipUpgradeFulfiller::class);
        $this->app->singleton(RewardFulfillerRegistry::class, static function (Application $app): RewardFulfillerRegistry {
            return new RewardFulfillerRegistry(
                bonusCash: $app->make(BonusCashFulfiller::class),
                cashbackBoost: $app->make(CashbackBoostFulfiller::class),
                freeBet: $app->make(FreeBetFulfiller::class),
                vipUpgrade: $app->make(VipUpgradeFulfiller::class),
            );
        });
    }

    public function boot(): void
    {
        Gate::policy(MemberBankAccount::class, MemberBankAccountPolicy::class);
        Gate::policy(RewardClaim::class, RewardClaimPolicy::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                AccrueCashbackCommand::class,
                PruneLoginEventsCommand::class,
            ]);
        }

        // Deferred until every provider has booted so event auto-discovery has
        // already registered its listeners; only then can we avoid duplicates.
        $this->app->booted(function (): void {
            $this->bootLoginListeners();
        });
        $this->bootBetReversalBridge();
        $this->bootMfaChallengeBridge();
        $this->bootSchedule();
    }

    /**
     * Explicit listener wiring so login history never depends on event
     * auto-discovery being enabled. Each pair is registered at most once: if
     * discovery (or the app EventServiceProvider) already bound the listener,
     * it is left alone, so no login is ever recorded twice.
     */
    private function bootLoginListeners(): void
    {
        /** @var Dispatcher $events */
        $events = $this->app->make('events');

        foreach ([Login::class, Failed::class, Lockout::class, MfaChallengeVerified::class] as $event) {
            $this->listenOnce($events, $event, RecordLoginEvent::class);
        }

        $this->listenOnce($events, NewDeviceLoginDetected::class, NotifyOnNewDeviceLogin::class);
    }

    private function listenOnce(Dispatcher $events, string $event, string $listener): void
    {
        if (method_exists($events, 'getRawListeners')) {
            $raw = $events->getRawListeners()[$event] ?? [];

            foreach ($raw as $registered) {
                if ($this->isSameListener($registered, $listener)) {
                    return;
                }
            }
        }

        foreach ($events->getListeners($event) as $registered) {
            if (! $registered instanceof \Closure) {
                continue;
            }

            $bound = (new \ReflectionFunction($registered))->getStaticVariables()['listener'] ?? null;

            if ($this->isSameListener($bound, $listener)) {
                return;
            }
        }

        $events->listen($event, [$listener, 'handle']);
    }

    private function isSameListener(mixed $registered, string $listener): bool
    {
        if (is_string($registered)) {
            return $registered === $listener || str_starts_with($registered, $listener.'@');
        }

        if (is_array($registered)) {
            $class = $registered[0] ?? null;

            return (is_string($class) && $class === $listener)
                || (is_object($class) && $class::class === $listener);
        }

        return false;
    }

    private function bootBetReversalBridge(): void
    {
        Bet::updated(function (Bet $bet): void {
            if (! $bet->wasChanged('status')) {
                return;
            }

            if (in_array($bet->status, [BetStatus::Cancelled, BetStatus::Refunded], true)) {
                app(CashbackAccrualService::class)->reverse($bet, 'bet_status_'.$bet->status->value);
            }
        });
    }

    private function bootMfaChallengeBridge(): void
    {
        MfaChallenge::created(function (MfaChallenge $challenge): void {
            if ($challenge->status !== MfaChallengeStatus::Pending) {
                return;
            }

            /** @var User|null $member */
            $member = User::query()->find($challenge->user_id);
            if (! $member instanceof User) {
                return;
            }

            app(LoginEventRecorder::class)->recordFromRequest(
                request: request(),
                member: $member,
                identifier: (string) ($member->email ?: $member->username),
                outcome: LoginEventOutcome::MfaRequired,
            );
        });
    }

    private function bootSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('cashback:accrue --period=daily')
                ->dailyAt('01:15')
                ->name('rewards-cashback-accrual-daily')
                ->withoutOverlapping()
                ->onOneServer();

            $schedule->command('cashback:accrue --period=weekly')
                ->weeklyOn(1, '01:30')
                ->name('rewards-cashback-accrual-weekly')
                ->withoutOverlapping()
                ->onOneServer();

            $schedule->job(new ExpireRewardArtifactsJob)
                ->hourlyAt(25)
                ->name('rewards-artifact-expiry')
                ->withoutOverlapping()
                ->onOneServer();

            $schedule->command('security:prune-login-events')
                ->dailyAt('02:45')
                ->name('security-login-events-prune')
                ->withoutOverlapping()
                ->onOneServer();
        });
    }
}
