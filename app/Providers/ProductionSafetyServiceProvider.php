<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Production configuration guard.
 *
 * BLOCKER CLOSURE -- production fixture contamination + unsafe production boot.
 *
 * The repository previously relied on documentation and reviewer discipline to
 * keep fake-result lanes, debug output and insecure session cookies out of
 * production. `.env.example` shipped all four fixture lanes enabled and
 * Composer copied it to `.env` on a fresh install, so the unsafe state was the
 * *default* state. config/security.php declared session policies that no code
 * ever read, and config/finance.php declared a payout safety switch that
 * nothing asserted.
 *
 * This provider turns those intentions into an enforced invariant: when
 * APP_ENV=production the application refuses to boot while any of them is
 * violated. Failing at boot is deliberate -- a lottery operator publishing
 * fixture results or leaking stack traces is worse than being down.
 *
 * Everything here is inert outside production.
 */
class ProductionSafetyServiceProvider extends ServiceProvider
{
    /**
     * Result lanes that must never be able to produce fake results in production.
     *
     * @var list<array{0:string,1:string}>
     */
    private const FIXTURE_LANES = [
        ['national_lottery.sources.fixture.enabled', 'NATIONAL_LOTTERY_FIXTURE_ENABLED'],
        ['weekly_lottery.sources.fixture.enabled', 'WEEKLY_LOTTERY_FIXTURE_ENABLED'],
        ['bingo_lottery.sources.fixture.enabled', 'BINGO_LOTTERY_FIXTURE_ENABLED'],
        ['pcso_lottery.sources.fixture.enabled', 'PCSO_LOTTERY_FIXTURE_ENABLED'],
    ];

    /**
     * Lanes whose official source must never silently degrade to a fixture.
     *
     * @var list<string>
     */
    private const LANES = [
        'national_lottery',
        'weekly_lottery',
        'bingo_lottery',
        'pcso_lottery',
    ];

    /**
     * The payout safety modes that do not move real money.
     *
     * @var list<string>
     */
    private const NON_LIVE_PAYOUT_MODES = ['DISABLED', 'DRY_RUN'];

    public function boot(): void
    {
        if (! $this->app->environment('production')) {
            return;
        }

        $violations = self::violations();

        if ($violations !== []) {
            throw new RuntimeException(
                "Refusing to boot: unsafe production configuration.\n  - "
                .implode("\n  - ", $violations)
            );
        }

        /*
        |----------------------------------------------------------------------
        | THE SAME CONTRACT, RE-ASSERTED ON EVERY REQUEST
        |----------------------------------------------------------------------
        |
        | A BOOT CHECK IS ONE MOMENT, AND PRODUCTION IS NOT. `config:cache`, an
        | ops console running `config()` in a tinker session, a queue worker
        | started before the change, a hot-reloaded FPM pool — all of them can
        | present a configuration the boot check never saw. The failure mode
        | that matters is specifically the one where a lane flips to a fixture
        | or a permissive driver AFTER the process started, because that is the
        | case where an operator believes the guard is on.
        |
        | RouteMatched fires once per HTTP request, after routing and before the
        | controller, which is early enough to stop the request before it reads
        | or writes anything. The check is config reads only: no query, no I/O,
        | no allocation worth measuring.
        */
        Event::listen(RouteMatched::class, static function (): void {
            self::assertRequestSafe();
        });
    }

    /**
     * The per-request half of the guard.
     *
     * @throws RuntimeException when any request-time violation is present
     */
    public static function assertRequestSafe(): void
    {
        $violations = self::requestViolations();

        if ($violations === []) {
            return;
        }

        throw new RuntimeException(
            "Refusing to serve this request: unsafe production configuration.\n  - "
            .implode("\n  - ", $violations)
        );
    }

    /**
     * Everything checked at boot PLUS everything checked per request.
     *
     * The two lists are kept separable so an existing test that asserts the
     * BOOT contract stays a test of the boot contract; the request-time lanes
     * have their own assertions in the Hardening lane.
     *
     * @return list<string>
     */
    public static function requestViolations(): array
    {
        return array_merge(self::violations(), self::surfaceViolations());
    }

    /**
     * Lanes whose safe value is a REQUEST-TIME question: they can be flipped by
     * a cache rewrite, and each one either publishes numbers or admits a file.
     *
     * @return list<string>
     */
    public static function surfaceViolations(): array
    {
        $violations = [];

        // ── Fixture result lane, re-asserted per request ────────────────────
        foreach (self::FIXTURE_LANES as [$configKey, $envKey]) {
            if (config($configKey) === true) {
                $violations[] = "{$configKey} is enabled ({$envKey}); a fixture lane may not answer a request in production.";
            }
        }

        foreach (self::LANES as $lane) {
            if (config("{$lane}.sources.fall_through_to_fixture") === true) {
                $violations[] = "{$lane}.sources.fall_through_to_fixture is true; a failed official fetch would answer this request with fixture data.";
            }
        }

        if (config('glo.official_source.mode') !== 'official') {
            $violations[] = 'GLO_OFFICIAL_SOURCE_MODE is not "official"; the fixture lane may not answer a request in production.';
        }

        if (config('glo.sources.fall_through_to_fixture') === true) {
            $violations[] = 'glo.sources.fall_through_to_fixture is true; a failed official GLO fetch would answer this request with synthetic vectors.';
        }

        // ── CAPTCHA ─────────────────────────────────────────────────────────
        // A 'local' challenge ships its own answer to the same client that
        // solves it, and 'fixed' is a test driver. Neither may guard a public
        // login form in production.
        if (config('auth_security.captcha.enabled') === true) {
            $driver = (string) config('auth_security.captcha.driver', '');

            if (in_array($driver, ['local', 'fixed'], true)) {
                $violations[] = sprintf(
                    'AUTH_CAPTCHA_DRIVER is "%s"; the local/fixed challenge answers itself and may not guard a production login. Configure hcaptcha or turnstile.',
                    $driver === '' ? 'unset' : $driver,
                );
            }

            if (in_array($driver, ['hcaptcha', 'turnstile'], true)) {
                $secret = (string) config('services.captcha.'.$driver.'.secret', '');

                if (trim($secret) === '') {
                    $violations[] = sprintf(
                        'AUTH_CAPTCHA_DRIVER is "%s" but its Siteverify secret is empty; every challenge would fail closed and no one could sign in.',
                        $driver,
                    );
                }
            }
        }

        // ── Antimalware ─────────────────────────────────────────────────────
        $antimalwareDriver = (string) config('antimalware.driver', '');

        if ($antimalwareDriver !== 'clamav') {
            $violations[] = sprintf(
                'ANTIMALWARE_DRIVER is "%s"; only "clamav" may scan uploads in production. The null driver reports not_scanned and must never answer a live upload.',
                $antimalwareDriver === '' ? 'unset' : $antimalwareDriver,
            );
        }

        // ── KYC provider ────────────────────────────────────────────────────
        $kycProvider = config('account.verification.provider');

        if (! is_string($kycProvider) || trim($kycProvider) === '') {
            $violations[] = 'ACCOUNT_KYC_PROVIDER is unset; the identity lane has no provider and every submission would fail without saying why.';
        } else {
            $map = (array) config('account.verification.providers', []);

            if (! isset($map[trim($kycProvider)])) {
                $violations[] = sprintf(
                    'ACCOUNT_KYC_PROVIDER is "%s", which is not a declared provider in account.verification.providers.',
                    trim($kycProvider),
                );
            }
        }

        // ── AML ─────────────────────────────────────────────────────────────
        $amlMode = strtolower(trim((string) config('aml.mode', '')));

        if ($amlMode === 'off') {
            $violations[] = 'AML_MODE is "off"; a production deployment may monitor or enforce, never disable screening entirely.';
        }

        return $violations;
    }

    /**
     * The full production safety contract, exposed so a release-blocking test
     * and the deployment preflight can assert it without booting production.
     *
     * @return list<string> human-readable violations; empty means safe
     */
    public static function violations(): array
    {
        $violations = [];

        // ── Fake result lanes ──────────────────────────────────────────────
        foreach (self::FIXTURE_LANES as [$configKey, $envKey]) {
            if (config($configKey) === true) {
                $violations[] = "{$configKey} is enabled ({$envKey}); fixture lanes may never run in production.";
            }
        }

        // A fixture-sourced result must never be reachable as official output.
        foreach (self::LANES as $lane) {
            if (config("{$lane}.sources.fall_through_to_fixture") === true) {
                $violations[] = "{$lane}.sources.fall_through_to_fixture is true; a failed official fetch would answer with fixture data.";
            }
        }

        // ── GLO: the lane this gate was missing entirely ─────────────────────
        //
        // THE HOLE, STATED PLAINLY. The four lanes above are checked. GLO was
        // not checked by anything, and it is the lane that MOVES THE MONEY:
        // a GLO result is what DrawResultConfirmationService publishes and what
        // RealPrizeSettlementService pays prizes against.
        //
        // config/glo.php:288 reads:
        //
        //     'mode' => env('GLO_OFFICIAL_SOURCE_MODE', 'fixture'),
        //
        // so a production deployment that simply never set
        // GLO_OFFICIAL_SOURCE_MODE runs the GLO lane on GloFixtureResultProvider
        // — which replays resources/glo/fixtures/lottery_result.json, whose own
        // `provenance.note` says:
        //
        //     "Numbers are synthetic test vectors, not a live GLO publication."
        //
        // Those numbers then flow through the ordinary path: ingested as
        // Pending, confirmed by a second operator who has no reason to doubt
        // them, published to `draw_results`, read by every public surface, and
        // paid out against winning slips. Nothing in that path is broken. Every
        // control works. The result is still not a real GLO publication, and real
        // money would be paid on it.
        //
        // A DEFAULT IS NOT A DECISION. `env(..., 'fixture')` is a sensible
        // default for a developer cloning the repository, and an unacceptable
        // one for a lottery operator. Production must therefore STATE that it is
        // on the official source — the same standard this gate already applies to
        // SESSION_SECURE_COOKIE and SANCTUM_TOKEN_EXPIRATION, which are also
        // merely-wrong-defaults rather than attacks.
        $gloMode = config('glo.official_source.mode');

        if ($gloMode !== 'official') {
            $violations[] = sprintf(
                'GLO_OFFICIAL_SOURCE_MODE is "%s" but must be "official" in production; the fixture lane replays '
                .'synthetic test vectors that would be published and paid out against as if they were a real GLO result.',
                is_string($gloMode) ? $gloMode : 'unset',
            );
        }

        // The ladder's fixture rung. Independent of the mode check above, because
        // a deployment can be on the official source AND still have configured a
        // fallback to the fixture lane — which would answer with synthetic
        // numbers on the day the official fetch failed, without anybody choosing
        // to answer with synthetic numbers that day.
        if (config('glo.sources.fall_through_to_fixture') === true) {
            $violations[] = 'glo.sources.fall_through_to_fixture is true; a failed official GLO fetch would answer with '
                .'synthetic fixture vectors and publish them.';
        }

        // ── An EXPLICIT fixture rung, even when official mode is selected ────
        //
        // The two checks above are not sufficient. A deployment can be on the
        // official source, with fall-through disabled, and STILL list 'fixture'
        // on the ladder — and then `GloResultProviderChain` walks to it the
        // moment the official endpoint is slow, because a rung that is in the
        // priority list is a rung the chain is entitled to consult. The
        // published numbers would be synthetic and nothing else in this gate
        // would have said so.
        //
        // The names are normalised the same way the chain normalises them
        // (trim + lowercase), so ' FIXTURE ' is caught too. Comparing raw
        // strings here and normalised strings there is exactly how a guard
        // passes while the thing it guards misbehaves.
        $gloPriority = config('glo.sources.priority', []);

        // A scalar is a legitimate configuration (the env key is a comma
        // separated string), so it is split rather than discarded: discarding
        // it is how a guard reads a broken value as "no fixture here".
        if (is_string($gloPriority)) {
            $gloPriority = array_map('trim', explode(',', $gloPriority));
        }

        $gloPriority = is_array($gloPriority) ? $gloPriority : [];

        foreach ($gloPriority as $rung) {
            if (! is_string($rung)) {
                continue;
            }

            if (in_array(strtolower(trim($rung)), ['fixture', 'fixture_only'], true)) {
                $violations[] = 'glo.sources.priority contains fixture; a fixture rung on the official ladder could answer '
                    .'a draw with synthetic test vectors even while mode is "official".';

                break;
            }
        }

        // ── Debug / disclosure ─────────────────────────────────────────────
        if (config('app.debug') === true) {
            $violations[] = 'APP_DEBUG is true; stack traces and environment values would be served publicly.';
        }

        // ── Session and cookie integrity ───────────────────────────────────
        // These mirror config/security.php, which until now was documentation
        // that nothing enforced.
        if (config('security.session.require_secure_cookie_in_production', true) && config('session.secure') !== true) {
            $violations[] = 'SESSION_SECURE_COOKIE must be true in production.';
        }

        if (config('security.session.require_encryption_in_production', true) && config('session.encrypt') !== true) {
            $violations[] = 'SESSION_ENCRYPT must be true in production.';
        }

        $expectedDriver = config('security.session.expected_driver');
        if (is_string($expectedDriver) && $expectedDriver !== '' && config('session.driver') === 'file') {
            $violations[] = sprintf(
                'SESSION_DRIVER is "file" but the security policy expects "%s"; file sessions cannot be shared across instances.',
                $expectedDriver
            );
        }

        // ── The cache store, when the cache is load-bearing as a control ────
        //
        // THE ARGUMENT IS THE SAME ONE, AND IT WAS APPLIED TO SESSIONS ONLY.
        // A file cache is atomic on ONE machine. `Cache::add` — the primitive
        // behind an idempotency claim, a replay check and a rate limiter — is
        // therefore a different answer on each container behind a load
        // balancer, and a meaningless one across a restart.
        //
        // WHAT THIS SPECIFICALLY PROTECTS, measured rather than asserted:
        //
        //   * the v1 payment webhook replay guard
        //     (PaymentWebhookService::processWebhookPayload claims
        //     'payment:webhook:seen:{gateway}:{eventId}' with Cache::add);
        //   * the idempotency middleware, whose whole promise is that a retried
        //     financial request is applied once, keyed in the cache;
        //   * the 90 throttle bindings, including `throttle:webhook` on the
        //     public money ingest surface — on file cache each instance grants
        //     its own full allowance, so the effective limit is N × the declared
        //     limit with N containers.
        //
        // The durable webhook_replay_guards table means the FIRST of those no
        // longer depends on the cache for its guarantee. The other two still do,
        // which is why this check exists rather than being retired.
        //
        // Set security.cache.require_shared_store_in_production to false only
        // for a genuinely single-instance deployment, and say so in the change
        // that does it — on file cache a restart also clears every claim, so the
        // window re-opens every deploy.
        if ((bool) config('security.cache.require_shared_store_in_production', true)) {
            $store = config('cache.default');

            if ($store === 'file') {
                $violations[] = 'CACHE_STORE is "file"; a per-node cache cannot back cross-instance idempotency, '
                    .'replay or throttle claims. A shared store (redis, database) is required, or set '
                    .'security.cache.require_shared_store_in_production to false for a single-instance deployment.';
            }
        }

        // ── Queue ──────────────────────────────────────────────────────────
        if (config('queue.default') === 'sync') {
            $violations[] = 'QUEUE_CONNECTION is sync; financial and webhook jobs would run inline in the HTTP request with no retries.';
        }

        // ── API credentials ────────────────────────────────────────────────
        if (blank(config('sanctum.expiration'))) {
            $violations[] = 'SANCTUM_TOKEN_EXPIRATION is empty; API bearer tokens would never expire.';
        }

        // ── Money movement safety gate ─────────────────────────────────────
        //
        // config/finance.php declares the switch:
        //   DISABLED (default) -- settlement may prepare payout rows, never credits
        //   DRY_RUN            -- same, plus explicit dry-run audit markers
        //   LIVE               -- actual wallet credit via WalletService + ledger
        //
        // Nothing asserted it. LIVE is legitimate once real-money payout is
        // commissioned, but it may only be reached by an explicit, recognised
        // value -- never by a typo falling through to a truthy default, and
        // never while the automated payout path is also switched on without a
        // four-eyes approval threshold.
        $safetyMode = strtoupper((string) config('finance.prize_payout.safety_mode', 'DISABLED'));

        if (! in_array($safetyMode, array_merge(self::NON_LIVE_PAYOUT_MODES, ['LIVE']), true)) {
            $violations[] = sprintf(
                'PRIZE_PAYOUT_SAFETY_MODE is "%s", which is not one of DISABLED, DRY_RUN or LIVE; an unrecognised value must never be treated as safe.',
                $safetyMode
            );
        }

        if ($safetyMode === 'LIVE') {
            $requireApprovalAt = (string) config('finance.prize_payout.require_approval_at_or_above', '');

            if ($requireApprovalAt === '' || ! is_numeric($requireApprovalAt)) {
                $violations[] = 'PRIZE_PAYOUT_SAFETY_MODE is LIVE but PRIZE_PAYOUT_REQUIRE_APPROVAL_AT is not a number; live payout without an approval threshold is unattended money movement.';
            }
        }

        if (config('lottery.payouts.auto_process') === true && $safetyMode !== 'LIVE') {
            $violations[] = 'lottery.payouts.auto_process is enabled while PRIZE_PAYOUT_SAFETY_MODE is not LIVE; the two switches disagree about whether money moves.';
        }

        return $violations;
    }
}
