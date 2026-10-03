<?php

declare(strict_types=1);

namespace App\Providers;

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
