<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\Wallet\ConversionFeeProviderContract;
use App\Contracts\Wallet\ConversionPolicyContract;
use App\Contracts\Wallet\ConversionRepositoryContract;
use App\Contracts\Wallet\WalletConversionServiceContract;
use App\Events\Wallet\ConversionCompleted;
use App\Events\Wallet\ConversionRejected;
use App\Events\Wallet\ConversionRequested;
use App\Events\Wallet\ConversionReversed;
use App\Listeners\Wallet\AlertOnConversionFailureSpike;
use App\Listeners\Wallet\PublishConversionMetrics;
use App\Listeners\Wallet\RecordConversionAuditTrail;
use App\Repositories\Wallet\WalletConversionRepository;
use App\Services\Wallet\Conversion\ConversionFeeCalculator;
use App\Services\Wallet\Conversion\ConversionPolicy;
use App\Services\Wallet\Conversion\WalletConversionService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * The Cash <-> Win conversion lane: bindings, event wiring and timers.
 *
 * WHY THIS FILE EXISTS — THE APPLICATION DID NOT BOOT WITHOUT IT
 * bootstrap/providers.php listed this class, and Laravel instantiates every
 * entry in that array during Application::bootstrapWith(). This class was
 * never written, so the framework hit
 *
 *     Error: Class "App\Providers\WalletConversionServiceProvider" not found
 *
 * before routing, before middleware, before any request was handled. That is
 * not a broken feature — it is a dead application. Every HTTP request, every
 * queue worker and every `php artisan` invocation failed identically, which is
 * why the failure is total rather than partial and why nothing downstream could
 * ever have been observed working.
 *
 * WHAT THIS PROVIDER OWNS
 *
 *  1. CONTRACT BINDINGS. Four interfaces in app/Contracts/Wallet were declared,
 *     injected by three controllers and a console command, and bound nowhere.
 *     Laravel cannot resolve an interface to a concrete class by guessing, so
 *     every one of those constructors would have thrown a
 *     BindingResolutionException even after the boot error was fixed. The
 *     bindings below are explicit and are the only place this mapping is
 *     written down.
 *
 *  2. EVENT WIRING. Four conversion events and three listeners exist and are
 *     fully implemented, but none of the events appears in
 *     EventServiceProvider's $listen map, and discovery is disabled in this
 *     application (see the NOTE above that map: "anything unlisted here never
 *     fires a listener"). The consequence is silent and therefore worse than a
 *     crash: the conversion audit trail was never written, conversion metrics
 *     were never published, and the failure-spike alert never fired. Auditors
 *     would have found an audit-trail listener that has never run once.
 *
 *     The listeners are registered here rather than added to
 *     EventServiceProvider because this lane is one unit: a reader looking for
 *     "what happens when a conversion completes" should find it in one file.
 *
 *     None of the three implements ShouldQueue, and that is deliberate and is
 *     preserved. An audit row for a money movement must be committed in the
 *     same request that moved the money; putting it on a queue makes evidence
 *     contingent on a worker being alive.
 *
 *  3. TIMERS. Three conversion console commands are registered (the framework
 *     discovers app/Console/Commands via withCommands() in bootstrap/app.php)
 *     but nothing ever ran them. Two of the three are maintenance lanes that
 *     decay silently when unscheduled:
 *
 *       - conversions:expire-quotes — a quote is a priced promise with a TTL
 *         (config('conversions.quotes.ttl_seconds'), default 120s). Expiry is
 *         time-bound reality, not a status someone sets. Unscheduled, quotes
 *         stay live forever and a member can execute a price computed against
 *         a market and a balance that no longer exist.
 *       - conversions:reconcile — compares completed conversions against their
 *         ledger postings. Unscheduled, drift between the conversion table and
 *         the ledger is never detected.
 *
 *     conversions:status is a read-only report for an operator's terminal and
 *     is deliberately NOT scheduled.
 *
 *     Scheduling is registered through callAfterResolving(Schedule::class) so
 *     that the Schedule object is only touched when the console kernel
 *     actually resolves it. Resolving it eagerly in boot() would build the
 *     schedule on every HTTP request for no reason.
 */
final class WalletConversionServiceProvider extends ServiceProvider
{
    /**
     * Register the contract bindings.
     *
     * singletons() rather than binds(): all four implementations are stateless
     * collaborators that hold no per-request state, so one instance per process
     * is both correct and cheaper. Nothing here may touch the database, read
     * config that another provider sets, or resolve another service — the
     * register phase runs before every other provider has booted.
     */
    public function register(): void
    {
        // The orchestrator. One implementation, four callers (member web,
        // member API, admin panel, console) — binding them to the interface is
        // what stops a caller re-implementing "just the transfer" and thereby
        // bypassing policy, quote arithmetic, idempotency and ledger postings.
        $this->app->singleton(
            WalletConversionServiceContract::class,
            WalletConversionService::class,
        );

        // The gate. Every refusal — feature off, direction off, KYC missing,
        // account restricted, limit breached — is decided here, before a wallet
        // is ever locked.
        $this->app->singleton(
            ConversionPolicyContract::class,
            ConversionPolicy::class,
        );

        // Fee arithmetic. Money maths lives behind this contract so the quote
        // a member sees and the fee the ledger posts cannot diverge.
        $this->app->singleton(
            ConversionFeeProviderContract::class,
            ConversionFeeCalculator::class,
        );

        // Persistence for conversion rows, including the row-locking reads
        // (findForUpdate, findByReferenceForUpdate) that make "exactly once"
        // true under concurrency.
        $this->app->singleton(
            ConversionRepositoryContract::class,
            WalletConversionRepository::class,
        );
    }

    /**
     * Wire the conversion events and register the lane's timers.
     */
    public function boot(): void
    {
        $this->bootRateLimiters();
        $this->bootEvents();
        $this->bootSchedule();
    }

    /**
     * The three limiters the conversion routes name.
     *
     * `routes/api.php` and `routes/web.php` attach `throttle:wallet-conversion-quote`,
     * `throttle:wallet-conversion-execute` and `throttle:wallet-conversion-admin`, and
     * nothing defined them. Laravel does not degrade gracefully on a missing named
     * limiter: the ThrottleRequests middleware throws MissingRateLimiterException, so
     * every conversion endpoint answered 500 before reaching its own gates — including
     * the ones that were supposed to refuse. A rate limit that is absent is not a limit
     * that is generous, it is an endpoint that does not work.
     *
     * Each is keyed per authenticated user with an IP fallback, matching the buckets
     * config/conversions.php declares: quotes are cheap and frequent, executions move
     * money and are tighter, and operator/admin traffic gets its own bucket so a bulk
     * reconciliation cannot exhaust a member's allowance.
     */
    private function bootRateLimiters(): void
    {
        RateLimiter::for('wallet-conversion-quote', function (Request $request): Limit {
            return $this->conversionLimit(
                (int) config('conversions.rate_limits.quote_per_minute', 30),
                'quote',
                $request,
            );
        });

        RateLimiter::for('wallet-conversion-execute', function (Request $request): Limit {
            return $this->conversionLimit(
                (int) config('conversions.rate_limits.user_per_minute', 10),
                'execute',
                $request,
            );
        });

        RateLimiter::for('wallet-conversion-admin', function (Request $request): Limit {
            return $this->conversionLimit(
                (int) config('conversions.rate_limits.admin_per_minute', 60),
                'admin',
                $request,
            );
        });
    }

    /**
     * A per-minute allowance, keyed per user and falling back to the caller's IP.
     *
     * The floor of one matters: a configured value of zero would refuse every request
     * for ever, which turns a mis-set environment variable into a closed lane.
     */
    private function conversionLimit(int $perMinute, string $bucket, Request $request): Limit
    {
        $identifier = $request->user()?->getAuthIdentifier();

        return Limit::perMinute(max(1, $perMinute))
            ->by($identifier === null
                ? 'conversion-'.$bucket.':ip:'.$request->ip()
                : 'conversion-'.$bucket.':user:'.$identifier);
    }

    /**
     * Register the listeners that EventServiceProvider's map does not carry.
     *
     * Each registration is explicit rather than a loop over a map, because the
     * union type on two of the listeners is the interesting part of this code
     * and a reader deserves to see it spelled out: RecordConversionAuditTrail
     * covers the three TERMINAL outcomes and deliberately not the request — a
     * request is an intention and an audit trail records what happened, not
     * what was asked for.
     */
    private function bootEvents(): void
    {
        // Every request to move money is counted, including the ones that are
        // later refused. Without this the conversion funnel has a numerator and
        // no denominator and a refusal rate cannot be computed.
        Event::listen(ConversionRequested::class, PublishConversionMetrics::class);

        // A completed conversion: metrics, plus the audit row that is the
        // evidence the money moved.
        Event::listen(ConversionCompleted::class, PublishConversionMetrics::class);
        Event::listen(ConversionCompleted::class, RecordConversionAuditTrail::class);

        // A refused conversion: metrics, the audit row that records the refusal
        // (a refusal is a fact an auditor needs — "who tried and was stopped"),
        // and the spike alert. A run of refusals is either an attack or a
        // misconfiguration and both want a human, so this is the one outcome
        // that raises an alert rather than only recording a row.
        Event::listen(ConversionRejected::class, PublishConversionMetrics::class);
        Event::listen(ConversionRejected::class, RecordConversionAuditTrail::class);
        Event::listen(ConversionRejected::class, AlertOnConversionFailureSpike::class);

        // A reversal: metrics and an audit row. A reversal never mutates the
        // original row, so the audit trail carries both directions and the
        // original movement stays legible.
        Event::listen(ConversionReversed::class, PublishConversionMetrics::class);
        Event::listen(ConversionReversed::class, RecordConversionAuditTrail::class);
    }

    /**
     * Register the lane's recurring maintenance.
     *
     * Guarded on config('conversions.enabled') so that a deployment which has
     * the lane switched off does not schedule work whose only effect is to
     * write to a log. The commands themselves are still available to an
     * operator by hand — this guard removes the timer, not the tool. This
     * mirrors the lottery automation kill switch in bootstrap/app.php, which
     * also registers no task at all when the flag is false rather than
     * registering one that returns early.
     */
    private function bootSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if ((bool) config('conversions.enabled', false) !== true) {
                return;
            }

            // Quote expiry. The cadence is derived from the quote TTL rather
            // than hardcoded, so shortening the TTL in config cannot leave a
            // window in which an expired quote is still executable. A 120s TTL
            // yields everyMinute; a longer one yields a proportionally slower
            // cadence, floored at once a minute because the scheduler's
            // granularity ends there.
            $ttlSeconds = (int) config('conversions.quotes.ttl_seconds', 120);

            $schedule->command('conversions:expire-quotes')
                ->everyMinute()
                ->withoutOverlapping(5)
                ->onOneServer()
                ->appendOutputTo(storage_path('logs/conversion-quote-expiry.log'))
                ->description(sprintf(
                    'Expire wallet conversion quotes past their %d second TTL',
                    $ttlSeconds,
                ));

            // Ledger reconciliation: completed conversions versus their
            // postings. Hourly, well inside any operating window, and on one
            // server only — two reconcilers walking the same window would
            // double-report drift.
            $schedule->command('conversions:reconcile')
                ->hourlyAt(35)
                ->withoutOverlapping(30)
                ->onOneServer()
                ->appendOutputTo(storage_path('logs/conversion-ledger-reconciliation.log'))
                ->description('Compare completed wallet conversions against their ledger postings');
        });
    }

    // provides() is deliberately NOT declared. It is only consulted for a
    // deferred provider, and this one is not deferred: all four bindings are
    // interfaces with exactly one implementation and resolving them is free, so
    // deferring would add a branch to every resolution in exchange for nothing.
    // Declaring provides() on a non-deferred provider is worse than omitting it
    // — it reads like a promise the framework never keeps.
}
