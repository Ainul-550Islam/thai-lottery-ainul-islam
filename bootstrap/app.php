<?php

use App\Console\Scheduling\ReconciliationCadence;
use App\Exceptions\ChatException;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Http\Middleware\EnsureChatGuestToken;
use App\Http\Middleware\EnsureDrawIsOpen;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureWalletIsActive;
use App\Http\Middleware\GloEnsurePermission;
use App\Http\Middleware\PublicLegalHeaders;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\VerifyWebhookSignature;
use App\Http\Responses\ApiResponse;
use App\Http\Support\BetPurchaseErrorMapper;
use App\Jobs\Chat\CloseIdleChatConversationsJob;
use App\Jobs\Chat\RedactChatMessagesJob;
use App\Jobs\Chat\ScanChatSlaBreachesJob;
use App\Jobs\Chat\WarnIdleChatConversationsJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
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
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
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
        // The name below is the command's declared signature, not a shortened alias.
        // A schedule line that names a missing command does not fail loudly: the task
        // runs, Artisan writes "command not found" into the task's own log file, and
        // the retention never happens. That is what this entry did before it was
        // corrected, for every day it existed, while its own comment above promised
        // the table "is pruned on a schedule".
        $schedule->command('cache:purge-webhook-replay-guards')
            ->dailyAt('03:20')
            ->withoutOverlapping(30)
            ->onOneServer()
            ->timezone(config('app.timezone', 'UTC'))
            ->appendOutputTo(storage_path('logs/webhook-replay-guard-purge.log'))
            ->description('Delete webhook replay-guard rows whose retention horizon has passed');

        // ---------------------------------------------------------------------
        // GLO freeze limitation window
        // ---------------------------------------------------------------------
        //
        // A GLO freeze is a legal hold on one ticket's payout, and every freeze row
        // carries an expiry: the API accepts one, and where it does not, the service
        // defaults it to the draw date plus config('glo.freeze.request_limitation_years')
        // (two years, the limitation window). Past that date the state machine says
        // Frozen to Expired, and hasActiveEffectiveFreeze() - the gate every claim
        // approval and every payment execution consults - stops blocking.
        //
        // What nothing did was produce that transition. Its only caller was
        // glo:expire-freezes, which no schedule, admin button or job ever invoked, while
        // the audit note it writes reads "Scheduled expiry reached". The gate also
        // counts a freeze that is past its expiry but not yet swept as still blocking,
        // so the consequence is not a wrongly-paid winner: it is a winner whose money
        // stays held after the app's own limitation window has closed, indefinitely, with
        // no audit row showing that anyone decided it.
        //
        // Hourly, not daily. The window being enforced is measured in years, so release
        // is not latency-sensitive, but a daily entry would hold a payout up to 24 hours
        // past its own expiry date for no reason at all. The sweep is one indexed range
        // read plus a write per due row, and it is idempotent, because expired rows stop
        // matching the selection.
        $schedule->command('glo:expire-freezes')
            ->hourly()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->timezone(config('app.timezone', 'UTC'))
            ->appendOutputTo(storage_path('logs/glo-freeze-expiry.log'))
            ->description('Expire GLO ticket freezes whose limitation window has passed, releasing the payout hold');

        // ---------------------------------------------------------------------
        // Contact message retention
        // ---------------------------------------------------------------------
        //
        // config('contact.retention') declares a 365-day window for contact messages
        // marked resolved, archived or spam, and PurgeContactMessages enforces exactly
        // that. Nothing ran it, so the declared window was a comment: names, emails,
        // phone numbers and message bodies stayed in contact_messages until a human
        // happened to remember the command existed. The chat lane already shows the
        // intended shape - a scheduled producer plus a manual command twin - and this
        // closes the same gap for contact.
        //
        // Unresolved messages are never touched, however old, because a support request
        // disappearing because it aged is a worse failure than a table that grows. The
        // command prints counts and refuses to print content, which is what makes writing
        // its output to a log file here safe.
        $schedule->command('contact:purge')
            ->dailyAt('03:40')
            ->withoutOverlapping(30)
            ->onOneServer()
            ->timezone(config('app.timezone', 'UTC'))
            ->appendOutputTo(storage_path('logs/contact-retention-purge.log'))
            ->description('Delete contact messages resolved, archived or marked spam past the configured retention window');

        // ---------------------------------------------------------------------
        // Ledger reconciliation (detective control)
        // ---------------------------------------------------------------------
        //
        // finance:reconcile compares wallets, financial transactions and ledger
        // entries and reports the differences. The service is read-only by design -
        // it never "fixes" a balance to make the numbers agree - so this is the one
        // money-adjacent scheduled task that can run unattended without deciding
        // anything about a customer's funds.
        //
        // Two config keys described this behaviour and neither was read by any code:
        // 'auto_reconcile' said it happened automatically and 'reconciliation_interval'
        // said how often. The schedule is now built from both, which is what makes the
        // keys mean something. The cadence conversion lives in ReconciliationCadence
        // rather than in this file, so that the rule can be tested directly instead of
        // being re-derived here.
        //
        // --dry-run is passed on purpose even though the service mutates nothing: it
        // stamps the audit record with the mode that ran, and it means that if the
        // service ever gained a repair path, an unattended schedule would still not
        // be allowed to use it.
        if (ReconciliationCadence::isAutoReconcileEnabled(config('finance.ledger.auto_reconcile', false))) {
            $schedule->command('finance:reconcile --dry-run')
                ->cron(ReconciliationCadence::expressionFor(config('finance.ledger.reconciliation_interval', ReconciliationCadence::DEFAULT_INTERVAL_SECONDS)))
                // A reconciliation that scans a large ledger is not instant, and two
                // overlapping runs would both write an audit row for the same window
                // and produce two reports that disagree about what they saw.
                ->withoutOverlapping(30)
                // Every host running cron would otherwise reconcile the same ledger.
                ->onOneServer()
                ->timezone(config('app.timezone', 'UTC'))
                ->appendOutputTo(storage_path('logs/finance-reconciliation.log'))
                ->description('Compare wallets, transactions and ledger entries; report discrepancies (read-only)');
        }

        // ---------------------------------------------------------------------
        // Operational reporting lanes
        // ---------------------------------------------------------------------
        //
        // Four commands existed whose only purpose was to answer "is the running
        // platform alright" - queue:health, ops:evaluate-alerts,
        // glo:provider-health, contact:delivery-health - plus chat:health for the
        // live desk. All four took no required argument, wrote no row, and exited 0
        // when run. None of them was in the schedule, so all four produced nothing:
        // the reports existed only in the minutes after a human thought to type them.
        //
        // That is the same defect this file already fixed twice for data hygiene
        // (contact:purge, glo:expire-freezes), and the gate that caught those -
        // ScheduledClockProducersTest - could not see it, because its verb list is
        // purge|expire|sweep|prune. A health report is not a purge: it does not
        // mutate anything, so nobody experiences a consequence until an incident,
        // which is exactly when the report would have been read.
        //
        // The deployment runbook is explicit that cron holds one entry,
        // `php artisan schedule:run`, and it then tells the operator to run
        // `php artisan queue:health` by hand. Wiring these lanes is what makes the
        // runbook's own automation mechanism complete instead of competing with it.
        //
        // Cadences are not taste. ops:evaluate-alerts is the one that has to be
        // frequent: OperationalAlertService::DEFAULT_THROTTLE_SECONDS is 900, and a
        // lane that ran every 20 minutes could miss an entire cooldown, so five
        // minutes gives three evaluations inside every window. glo:provider-health
        // reports a circuit breaker that opens for
        // config('glo.sources.breaker.window_seconds') (300 by default), so it reports on
        // the same scale or its output describes a source that may have recovered
        // while nobody was looking. queue:health's signals (pending, failed, stuck)
        // persist until a worker clears them, so ten minutes costs little and still
        // beats the hour it previously could have waited. The two config-state
        // reporters run once a day: whether SMTP is configured does not change
        // between runs, and a dated line per day is the useful artefact.
        //
        // Every entry writes to its own file under storage/logs. That is deliberate
        // rather than tidy: two lanes appending to one file makes the interleaved
        // output unreadable at the moment it matters, and OperationalLaneTruthTest
        // now asserts the paths stay distinct.
        if ((bool) config('ops.lanes.alerts', true)) {
            $schedule->command('ops:evaluate-alerts')
                ->everyFiveMinutes()
                ->withoutOverlapping(10)
                ->onOneServer()
                ->timezone(config('app.timezone', 'UTC'))
                ->appendOutputTo(storage_path('logs/ops-alerts.log'))
                ->description('Evaluate operational metrics against their thresholds and dispatch alerts (read-only)');
        }

        if ((bool) config('ops.lanes.queue_health', true)) {
            $schedule->command('queue:health --json')
                ->everyTenMinutes()
                ->withoutOverlapping(15)
                ->onOneServer()
                ->timezone(config('app.timezone', 'UTC'))
                ->appendOutputTo(storage_path('logs/queue-health.log'))
                ->description('Report queue liveness, pending, failed and stuck jobs as JSON (read-only)');
        }

        if ((bool) config('ops.lanes.provider_health', true)) {
            $schedule->command('glo:provider-health --json')
                ->everyFiveMinutes()
                ->withoutOverlapping(10)
                ->onOneServer()
                ->timezone(config('app.timezone', 'UTC'))
                ->appendOutputTo(storage_path('logs/glo-provider-health.log'))
                ->description('Report result-provider configuration and circuit-breaker state for each source (read-only)');
        }

        if ((bool) config('ops.lanes.delivery_health', true)) {
            $schedule->command('contact:delivery-health')
                ->dailyAt('03:45')
                ->withoutOverlapping(30)
                ->onOneServer()
                ->timezone(config('app.timezone', 'UTC'))
                ->appendOutputTo(storage_path('logs/contact-delivery-health.log'))
                ->description('Report whether contact-form forwarding is configured and believed to work (read-only)');
        }

        if ((bool) config('ops.lanes.chat_health', true) && (bool) config('chat.enabled', true)) {
            $schedule->command('chat:health --json')
                ->dailyAt('03:50')
                ->withoutOverlapping(30)
                ->onOneServer()
                ->timezone(config('app.timezone', 'UTC'))
                ->appendOutputTo(storage_path('logs/chat-health.log'))
                ->description('Report live-chat transport, broadcast connection and desk configuration (read-only)');
        }

        // ---------------------------------------------------------------------
        // Live chat desk automation
        // ---------------------------------------------------------------------
        //
        // Four sweeps keep the desk honest without anyone watching a clock:
        //
        //   warn   quiet conversations get the visible heads-up that the chat
        //          will close; the stamp makes each warning fire exactly once.
        //   close  conversations silent past the window are closed with reason
        //          idle and their transcript queued; the conditional close
        //          means a human close that wins by milliseconds is respected.
        //   sla    waiting conversations past the response-warning threshold
        //          are raised once each into the audit trail (never twice).
        //   redact content past its retention window is tombstoned off-peak.
        //
        // Cadences match the configs they serve: warning and SLA scans run
        // against minute-scale thresholds, the close sweep against a
        // 30-minute window, and retention against days. All four are
        // ShouldBeUnique and idempotent by selection, so a late scheduler or
        // a doubled dispatch costs one wasted no-op, never a double action.
        if ((bool) config('chat.enabled', true)) {
            $schedule->job(new WarnIdleChatConversationsJob)
                ->everyTwoMinutes()
                ->withoutOverlapping(5)
                ->onOneServer()
                ->timezone(config('app.timezone', 'UTC'));

            $schedule->job(new CloseIdleChatConversationsJob)
                ->everyFiveMinutes()
                ->withoutOverlapping(10)
                ->onOneServer()
                ->timezone(config('app.timezone', 'UTC'));

            $schedule->job(new ScanChatSlaBreachesJob)
                ->everyMinute()
                ->withoutOverlapping(5)
                ->onOneServer()
                ->timezone(config('app.timezone', 'UTC'));

            $schedule->job(new RedactChatMessagesJob)
                ->dailyAt('02:40')
                ->withoutOverlapping(60)
                ->onOneServer()
                ->timezone(config('app.timezone', 'UTC'));
        }

    })
    ->withMiddleware(function (Middleware $middleware): void {
        // The web group's CSRF entry is the framework class, which carries no exemptions
        // at all. The app's own subclass is where the signed-webhook exemption list lives,
        // so the subclass is put where it runs instead of being declared and registered by
        // nobody. No browser request changes: no web-group route matches either webhook
        // path, and the api group has no CSRF middleware. Both facts are measured against
        // the route table, not assumed.
        //
        // replaceInGroup, not replace(): Middleware::replace() maps only the GLOBAL stack
        // (its getGlobalMiddleware() applies $this->replacements, and the web group is built
        // separately), so using it here would have left this declaration inert in exactly the
        // way the class itself was. Measured, not assumed.
        // The web group keeps the framework's ValidateCsrfToken. This used to swap in
        // App\Http\Middleware\VerifyCsrfToken, a subclass whose only content is an $except list - which
        // meant the group MemberAuthParityTest reads off the kernel carried a name no guard checks for,
        // and the exemptions lived in a class instead of in the one place Laravel documents. The two
        // webhook paths are still exempt, declared here; the subclass stays for the Filament panel, which
        // builds its own middleware stack and cannot see this list.
        $middleware->validateCsrfTokens(except: [
            'api/v1/payments/webhook/*',
            'api/v1/payments/webhook/v2/*',
        ]);

        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'admin.auth' => Authenticate::class,
            'wallet.active' => EnsureWalletIsActive::class,
            // `draw.open` and `wallet.active` are the two aliases no route reaches, on
            // purpose: the draw window is enforced by BetValidationService behind
            // config(lottery.betting.require_open_draw) and the wallet state inside
            // BetPurchaseValidator, both under the wallet's own row lock - a duplicate
            // check up here would be the weaker of the two. The aliases stay registered
            // because the classes are the documented fallback for an endpoint outside the
            // purchase path; MiddlewareGuardReachabilityTest pins the exact set.
            'draw.open' => EnsureDrawIsOpen::class,
            'webhook.signature' => VerifyWebhookSignature::class,
            'throttle' => ThrottleRequests::class,
            'glo.permission' => GloEnsurePermission::class,
            // Cache policy for the public informational pages. The allow-list lives in
            // PublicLegalHeaders::CACHEABLE_PATHS, not here; the alias is attached to every
            // guest public route in routes/web.php on purpose and the class decides, so most
            // attachments are no-ops by design (pinned by PublicLegalCachingContractTest).
            'public.legal' => PublicLegalHeaders::class,
            // Establishes the anonymous chat visitor identity (cookie) before
            // any chat endpoint runs; see the middleware header for why it
            // must precede the controller.
            'chat.guest' => EnsureChatGuestToken::class,
        ]);

        // Applied globally: security headers, and a correlation id pinned on the way in and
        // echoed back out on the response so the whole request is traceable. Global append
        // (rather than web/api group append) is deliberate: the framework's /up health route
        // carries no middleware group at all, and an operator liveness probe is exactly the
        // kind of request that must still present security headers and a trace id.
        // Which forwarded headers may be believed. The proxy LIST needs no wiring here:
        // Illuminate\Http\Middleware\TrustProxies is already in the framework's default
        // global stack and its handle() falls back to config('trustedproxy.proxies'), which
        // is where TRUSTED_PROXIES is read (see config/trustedproxy.php).
        //
        // The mask has to be spelled literally, though. This closure runs from
        // ApplicationBuilder::withMiddleware() via afterResolving(HttpKernel::class), and
        // public/index.php resolves the HTTP kernel BEFORE $kernel->handle() runs the
        // bootstrappers - so LoadConfiguration has not happened yet. Calling config() here
        // dies with `ReflectionException: Class "config" does not exist`; measured, not
        // assumed. Silently surviving it would have been worse: trustProxies(headers: null)
        // changes nothing, so a config() read that came back empty would have looked
        // configured while the framework's own host-trusting default stayed in force.
        //
        // Everything except X-Forwarded-Host, deliberately. The framework default also trusts
        // HEADER_X_FORWARDED_HOST, and nothing here reads a forwarded host - zero hits for
        // X-Forwarded-Host, trusted_hosts or trustHosts across app, config, routes and
        // bootstrap - while an operator who sets TRUSTED_PROXIES=* to work behind a public CDN
        // would otherwise hand every client the Host the app echoes into its URLs. Host trust
        // belongs to $middleware->trustHosts(at: [...]), which validates against an allow
        // list; it stays unconfigured.
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_PROTO
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PREFIX
            | Request::HEADER_X_FORWARDED_AWS_ELB);

        $middleware->append(SecurityHeaders::class);
        $middleware->append(CorrelationIdMiddleware::class);
        // Locale negotiation is registered per group, not globally. The global stack
        // finishes before StartSession runs, so hasSession() is false there and the
        // session lane - the only thing that makes a language choice survive the next
        // page - cannot execute. Measured before this move: /about?lang=th rendered
        // Thai, then /terms with the same cookies rendered English again. Appending
        // inside a group puts it after that group's session middleware on web, and on
        // api (no session at all) leaves query + Accept-Language working as before.
        // LocaleNegotiationTest pins the persistence so it cannot move back.
        $middleware->appendToGroup('web', SetLocale::class);
        $middleware->appendToGroup('api', SetLocale::class);
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

        // Live-chat refusals carry their own HTTP status and a stable machine
        // code. They must render as JSON on ANY path (the widget lives at
        // /chat/* and the console at /operator/chat/*, not under /api/*), and
        // a refusal is never an unhandled fault — so it is mapped before the
        // generic API envelope and never reaches the 5xx logger.
        $exceptions->render(function (ChatException $e, Request $request) {
            return response()->json($e->toArray(), $e->httpStatus());
        });

        $exceptions->render(function (Throwable $e, Request $request) {
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
