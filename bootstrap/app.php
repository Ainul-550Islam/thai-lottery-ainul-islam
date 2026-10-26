<?php

use App\Exceptions\ResponsibleGamingLimitException;
use App\Exceptions\SelfExclusionException;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\AuthenticateBoundSession;
use App\Http\Middleware\BotDetectionMiddleware;
use App\Http\Middleware\ConfiguredIpAccessMiddleware;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Http\Middleware\EnsureAdult;
use App\Http\Middleware\EnsureDrawIsOpen;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureWalletConversionEnabled;
use App\Http\Middleware\EnsureWalletIsActive;
use App\Http\Middleware\GloEnsurePermission;
use App\Http\Middleware\HoneypotMiddleware;
use App\Http\Middleware\PublicLegalHeaders;
use App\Http\Middleware\RequireConversionIdempotencyKey;
use App\Http\Middleware\RequireCurrentLegalConsent;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TrackMemberPresence;
use App\Http\Middleware\VerifyCsrfToken;
use App\Http\Middleware\VerifyWebhookSignature;
use App\Http\Responses\ApiResponse;
use App\Http\Support\BetPurchaseErrorMapper;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Part 53: the broadcasting auth endpoint is an implicit guard
    // consumer — it authorizes private channels from the session user.
    // It must pass the same auth + password-binding checks as every
    // other authenticated web surface, not only the `web` group.
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['middleware' => ['web', 'auth', 'auth.session']])
    ->withCommands([
        // Registered explicitly rather than relying on discovery, so the console
        // surface of this application is something you can read in one place.
        __DIR__.'/../app/Console/Commands',
    ])
    ->withSchedule(function (Schedule $schedule): void {
        // ---------------------------------------------------------------------
        // Draw automation
        // ---------------------------------------------------------------------
        //
        // WHAT THIS FIXES
        // Phases 1 to 5.1 built a complete draw lifecycle - open, close, await
        // result, publish, settle - and nothing invoked it. config('lottery') had
        // declared draws on the 1st and 16th at 15:00 and an auto-close five minutes
        // before the draw since Phase 1, and no code read either value. A draw
        // existed only if somebody inserted a row and moved only if somebody called
        // a service by hand. This schedule is what makes the declared calendar real.
        //
        // ONE TASK, NOT FIVE
        // lottery:tick runs the five steps in order inside one process, because the
        // order matters within a single minute: a draw provisioned at 14:54 must be
        // able to open, and a draw whose cut-off is 14:55 must close, in the same
        // tick. Five separate scheduled tasks would spread that across five minutes
        // and make the effective cut-off drift.
        //
        // WHAT IS DELIBERATELY NOT SCHEDULED
        // Result publication. The official numbers come from outside this system and
        // config('lottery.results.require_admin_confirmation') says a human confirms
        // them, so publication is the operator command lottery:publish-result and
        // appears nowhere in this file.
        //
        // THE KILL SWITCH IS HONOURED HERE TOO
        // With config('lottery.automation.enabled') false, NO task is registered at
        // all - not a task that returns early. The commands carry the same guard, so
        // a manual run is also refused unless it is forced.
        if ((bool) config('lottery.automation.enabled', false) === true) {
            $expression = config('lottery.automation.tick_cron');
            $expression = is_string($expression) && trim($expression) !== '' ? trim($expression) : '* * * * *';

            $schedule->command('lottery:tick')
                ->cron($expression)
                // A tick that overruns must never run twice at once: two ticks could
                // both see the same draw as due. The lifecycle would refuse the
                // second transition anyway, but overlapping runs would fill the log
                // with refusals that look like defects.
                ->withoutOverlapping(10)
                // The scheduler must stay responsive for other work, and a tick that
                // settles a large draw is not instant.
                ->runInBackground()
                ->onOneServer()
                ->timezone(config('lottery.timezone', 'UTC'))
                ->appendOutputTo(storage_path('logs/lottery-tick.log'))
                ->description('Advance every draw that is due to its next lifecycle state');
        }

        // Capacity drift guard. Section 9b.17 proved a purchase is refused unless
        // a number_limits row exists for (draw_id, bet_type, number). Provisioning
        // in OpenDrawsCommand is best-effort by design, so a draw CAN open
        // under-provisioned and the symptom is silent. --check reports drift and
        // exits non-zero; it provisions nothing, because writing 2110 capacity rows
        // per draw unattended on a real-money table is not a timer's job.
        if ((bool) config('lottery.automation.enabled', false) === true) {
            $schedule->command('risk:provision-number-limits --open --check')
                ->hourly()
                ->withoutOverlapping(10)
                ->runInBackground()
                ->onOneServer()
                ->timezone(config('lottery.timezone', 'UTC'))
                ->appendOutputTo(storage_path('logs/risk-number-limits-check.log'))
                ->description('Report draws whose per-number capacity rows are missing or stale');
        }

        // ---------------------------------------------------------------------
        // Webhook replay-guard retention
        // ---------------------------------------------------------------------
        //
        // webhook_replay_guards backs the durable replay control on the inbound
        // payment webhook lane: UNIQUE (gateway, nonce) is what refuses a
        // replayed delivery, where a cache entry could not, because `Cache::add`
        // on the file driver is atomic on one machine and meaningless across two.
        //
        // That table grows with delivered traffic - one row per distinct signed
        // payload - so it is pruned on a schedule. The delete is bounded by the
        // ROW'S OWN expires_at, which is an indexed range delete, and never by a
        // recomputed "now minus N days": deleting a row that is still live
        // re-opens the replay window for that exact payload.
        //
        // It is NOT gated on lottery.automation.enabled. That flag is the draw
        // automation kill switch; the payment lane is not draw automation, and
        // tying retention to it would let an unrelated flag cause the guard table
        // to grow without bound and, at the limit, slow the hot payment path.
        $schedule->command('webhooks:purge-replay-guards')
            ->dailyAt('03:20')
            ->withoutOverlapping(30)
            ->onOneServer()
            ->timezone(config('app.timezone', 'UTC'))
            ->appendOutputTo(storage_path('logs/webhook-replay-guard-purge.log'))
            ->description('Delete webhook replay-guard rows whose retention horizon has passed');

        // ---------------------------------------------------------------------
        // Part 62/63 guards: settlement invariants and readiness
        // ---------------------------------------------------------------------
        //
        // WHAT THIS FIXES
        // Parts 62 and 63 built a settlement-invariant guard and a readiness
        // report, and Part 63's own schedule measurement showed that neither was
        // scheduled: 38 application commands were registered and most were never
        // run, including `settlement:verify-guards`, which
        // config/operations-readiness.php lists as required. A guard nobody runs
        // is indistinguishable from a guard that does not exist.
        //
        // Both are read-only. Neither repairs a result, reverses a payout, moves
        // an exclusion or changes a provider — they record and report, so running
        // them unattended cannot cause harm, and NOT running them is what causes
        // harm: a divergence found late is still a divergence.
        //
        // Neither is gated on lottery.automation.enabled. That flag is the draw
        // automation kill switch; settlement verification is not draw automation.
        //
        // WHAT THIS STILL DOES NOT PROVE
        // Registering a schedule entry is not evidence that the deployed host
        // runs `schedule:run`. Until cron is verified in the deployed
        // environment, these two commands run only where that is true.
        $schedule->command('settlement:verify-guards')
            ->dailyAt('04:05')
            ->withoutOverlapping(30)
            ->onOneServer()
            ->timezone(config('app.timezone', 'UTC'))
            ->appendOutputTo(storage_path('logs/settlement-guards.log'))
            ->description('Check published results, paid payouts and draw counters still agree');

        $schedule->command('operations:readiness-report')
            ->dailyAt('04:20')
            ->withoutOverlapping(15)
            ->onOneServer()
            ->timezone(config('app.timezone', 'UTC'))
            ->appendOutputTo(storage_path('logs/operations-readiness.log'))
            ->description('Report which provider lanes are configured and which commands are scheduled');

        $schedule->command('conversions:expire-quotes')
            ->everyFiveMinutes()
            ->withoutOverlapping(5)
            ->onOneServer()
            ->timezone(config('app.timezone', 'UTC'))
            ->appendOutputTo(storage_path('logs/conversion-quote-expiry.log'))
            ->description('Mark expired wallet conversion quotes so they cannot be executed');

        $schedule->command('conversions:reconcile-ledger')
            ->hourly()
            ->withoutOverlapping(15)
            ->onOneServer()
            ->timezone(config('app.timezone', 'UTC'))
            ->appendOutputTo(storage_path('logs/conversion-ledger-reconcile.log'))
            ->description('Reconcile completed wallet conversions against their ledger entries');

        $schedule->job(new \App\Jobs\Chat\WarnIdleChatConversationsJob)
            ->everyMinute()
            ->withoutOverlapping(5)
            ->onOneServer()
            ->description('Warn idle live-chat conversations before automatic closure');

        $schedule->job(new \App\Jobs\Chat\CloseIdleChatConversationsJob)
            ->everyMinute()
            ->withoutOverlapping(5)
            ->onOneServer()
            ->description('Close live-chat conversations that remain idle past the warning window');

        $schedule->job(new \App\Jobs\Chat\ScanChatSlaBreachesJob)
            ->everyMinute()
            ->withoutOverlapping(5)
            ->onOneServer()
            ->description('Scan open live-chat conversations for SLA breaches');

        $schedule->job(new \App\Jobs\Chat\RedactChatMessagesJob)
            ->dailyAt('02:35')
            ->withoutOverlapping(30)
            ->onOneServer()
            ->description('Redact retained live-chat messages past the PII retention window');

        $schedule->job(new \App\Jobs\ExpireBlockedIpRulesJob)
            ->everyFiveMinutes()
            ->withoutOverlapping(5)
            ->onOneServer()
            ->description('Deactivate expired IP block rules');

    })
    ->withMiddleware(function (Middleware $middleware): void {
        // Trusted proxies: ONE knob, read by the middleware that already runs.
        //
        // Illuminate\Http\Middleware\TrustProxies is in the framework's default global
        // stack and reads config('trustedproxy.proxies') itself, so the proxy list lives
        // in config/trustedproxy.php and nowhere else - pinning it here as well would
        // recreate the two-source drift this replaces.
        //
        // The header mask, however, cannot come from config: the middleware exposes it
        // only as a property or a static. The framework default believes
        // X-Forwarded-Host and X-Forwarded-Prefix too, which lets a trusted peer rewrite
        // the host a signed URL is generated for. Only FOR and PROTO are installed here.
        $middleware->trustProxies(headers:
            \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR
            | \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO
        );

        // VerifyCsrfToken enforces narrow signed-webhook CSRF exemptions only (never api/*).
        $middleware->validateCsrfTokens(except: VerifyCsrfToken::WEBHOOK_EXCEPTIONS);

        // Record only authenticated member activity, throttled to one write per minute.
        $middleware->web(append: [TrackMemberPresence::class]);
        $middleware->appendToGroup('web', SetLocale::class);
        $middleware->appendToGroup('api', SetLocale::class);

        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'adult' => EnsureAdult::class,
            'legal.current' => RequireCurrentLegalConsent::class,
            // Opt-in observation only until CAPTCHA/enforcement integration is complete.
            'bot' => BotDetectionMiddleware::class,
            'honeypot' => HoneypotMiddleware::class,
            'ip-access' => ConfiguredIpAccessMiddleware::class,
            'admin.auth' => Authenticate::class,
            // Part 52: fail closed on sessions with no password binding
            // (legacy pre-binding sessions) instead of adopting them.
            'auth.session' => AuthenticateBoundSession::class,
            'wallet.active' => EnsureWalletIsActive::class,
            'draw.open' => EnsureDrawIsOpen::class,
            'webhook.signature' => VerifyWebhookSignature::class,
            'throttle' => ThrottleRequests::class,
            'glo.permission' => GloEnsurePermission::class,
            // Safe public-cache headers for /about, /vision, /terms only (guest GETs).
            'public.legal' => PublicLegalHeaders::class,
            // Cash <-> Win conversion lane.
            'conversion.enabled' => EnsureWalletConversionEnabled::class,
            'conversion.idempotent' => RequireConversionIdempotencyKey::class,
            'conversion.idempotency' => RequireConversionIdempotencyKey::class,
            // Real-money licence gate — fail-closed on deposit/withdrawal/bet routes.
            'real_money.license' => \App\Http\Middleware\EnforceRealMoneyLicense::class,
        ]);

        // Applied globally: security headers, and a correlation id pinned on the way in and
        // echoed back out on the response so the whole request is traceable. Global append
        // (rather than web/api group append) is deliberate: the framework's /up health route
        // carries no middleware group at all, and an operator liveness probe is exactly the
        // kind of request that must still present security headers and a trace id.
        $middleware->append(SecurityHeaders::class);
        $middleware->append(CorrelationIdMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // ---------------------------------------------------------------------
        // API exception rendering (Phase 4.4)
        // ---------------------------------------------------------------------
        //
        // Every exception that escapes an /api/* route is rendered through the same
        // envelope the controllers use, and through the same mapper, so a client never
        // has to parse two different error shapes.
        //
        // WHY THIS EXISTS AT ALL, GIVEN THE CONTROLLERS ALREADY CATCH
        // The purchase controller catches Throwable around the purchase call, so domain
        // failures are already mapped there. This handler covers what a controller
        // cannot: a failure BEFORE the controller runs (auth middleware, throttle
        // middleware, route resolution, request validation) and anything genuinely
        // unexpected. Without it, those cases would return Laravel's own default shapes -
        // and in a misconfigured environment, a stack trace.
        //
        // WHAT IS NEVER IN THE RESPONSE
        // No stack trace, no file path, no line number, no SQL, no exception class name,
        // no wallet id, no balance. The mapper builds the body from a fixed set of codes
        // and a whitelist of safe context keys; it never forwards an exception message it
        // did not author. This holds regardless of APP_DEBUG - a production-shaped
        // response is what the API returns even when a developer has debug enabled
        // locally, because an API client should never receive a body whose shape depends
        // on a server setting.
        //
        // The detail is not lost. It goes to the log, where operators can read it and
        // players cannot.
        $exceptions->render(function (ResponsibleGamingLimitException|SelfExclusionException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::error(
                    strtolower($e->errorCode()),
                    'Responsible gaming policy refused the requested operation.',
                    422,
                );
            }

            return back()->withErrors([
                'limits' => 'Responsible gaming policy refused the requested operation.',
            ]);
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($e instanceof \App\Exceptions\ChatException) {
                $context = $e->logContext();
                $headers = [];
                if (isset($context['retry_after']) && is_int($context['retry_after']) && $context['retry_after'] > 0) {
                    $headers['Retry-After'] = (string) $context['retry_after'];
                }

                return response()->json($e->toArray(), $e->httpStatus(), $headers);
            }

            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            // An HttpResponseException CARRIES a response that an earlier layer built
            // deliberately - the rate limiter's own 429, for example, which the limiter
            // registered in AppServiceProvider already emits in this project's envelope.
            // Re-mapping it here would discard that response and replace a correct 429
            // with a generic 500, so it is passed through untouched.
            if ($e instanceof HttpResponseException) {
                return null;
            }

            $mapper = app(BetPurchaseErrorMapper::class);
            $mapped = $mapper->map($e);

            if ($mapped['status'] >= 500) {
                Log::error('api.unhandled_exception', [
                    'exception' => $e,
                    'path' => $request->path(),
                    'method' => $request->method(),
                    'user_id' => $request->user()?->getAuthIdentifier(),
                ]);
            }

            $headers = [];

            // Preserve Retry-After and X-RateLimit-* headers that the throttle
            // middleware attached, so a well-behaved client still learns when it may
            // retry even though the body has been reshaped.
            if ($e instanceof HttpExceptionInterface) {
                $headers = $e->getHeaders();
                unset($headers['Content-Type']);
            }

            return ApiResponse::error(
                $mapped['code'],
                $mapped['message'],
                $mapped['status'],
                $mapped['details'],
                $headers,
            );
        });

        // An unauthenticated API request must not be redirected to a login route.
        // Laravel's default is a redirect for non-JSON requests, and this project has no
        // web login route at all, so that default would turn a missing token into a
        // route-not-defined error instead of a clean 401.
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e): bool {
            return $request->is('api/*') || $request->expectsJson();
        });

        // ---------------------------------------------------------------------
        // Secrets are never flashed back to the session on a validation failure.
        // ---------------------------------------------------------------------
        //
        // Laravel's built-in list covers `password` and `password_confirmation`.
        // It does NOT cover the names this platform actually uses for credentials,
        // so a validation error on a form that carried an API key, a webhook
        // secret or a bearer token would flash the VALUE back into the session,
        // and from there into the old-input rendering of the form.
        //
        // This list used to live in app/Exceptions/Handler.php, which NOTHING
        // resolved: since Laravel 11 the exception handling pipeline is built by
        // `withExceptions()` below, and `App\Exceptions\Handler` was never bound
        // anywhere. The override was dead code — the guarantee it advertised was
        // not in force — which is the one kind of security code that is worse than
        // none, because a reviewer who finds it stops looking. The file is deleted
        // and the list is carried HERE, where it is actually applied.
        $exceptions->dontFlash([
            '_hp_token',
            'captcha_token',
            'captcha_answer',
            'cf-turnstile-response',
            'h-captcha-response',
            'g-recaptcha-response',
            'current_password',
            'password',
            'password_confirmation',
            'secret',
            'api_key',
            'api_secret',
            'webhook_secret',
            'token',
            'access_token',
            'refresh_token',
            'private_key',
            'card_number',
            'cvv',
            'pin',
        ]);
    })->create();
