<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Providers\ProductionSafetyServiceProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * RELEASE-BLOCKING production configuration contract.
 *
 * Fails the build when the shipped configuration would allow a production
 * deployment to publish fake results, leak stack traces, run financial jobs
 * inline, mint non-expiring API tokens, or derive its environment from the
 * development template.
 *
 * Every assertion here corresponds to a defect that was actually present in
 * this repository, not to a hypothetical one.
 */
#[Group('production-safety')]
final class ProductionConfigSafetyTest extends TestCase
{
    /**
     * The four fake-result lanes.
     *
     * @var list<string>
     */
    private const FIXTURE_ENV_KEYS = [
        'NATIONAL_LOTTERY_FIXTURE_ENABLED',
        'WEEKLY_LOTTERY_FIXTURE_ENABLED',
        'BINGO_LOTTERY_FIXTURE_ENABLED',
        'PCSO_LOTTERY_FIXTURE_ENABLED',
    ];

    /**
     * @var list<string>
     */
    private const LANES = [
        'national_lottery',
        'weekly_lottery',
        'bingo_lottery',
        'pcso_lottery',
    ];

    // ─────────────────────────────────────────────────────────────────────
    // Fixture lanes
    // ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function env_example_never_enables_a_fixture_lottery_lane(): void
    {
        $example = (string) file_get_contents(base_path('.env.example'));

        foreach (self::FIXTURE_ENV_KEYS as $key) {
            $this->assertStringNotContainsString(
                "{$key}=true",
                $example,
                "{$key} is enabled in .env.example. A fixture lane must never be on by default."
            );
        }
    }

    #[Test]
    public function the_ci_environment_never_enables_a_fixture_lottery_lane(): void
    {
        $this->assertFileExists(
            base_path('.env.ci'),
            'CI must have a committed environment file so it never copies .env.example.'
        );

        $ci = (string) file_get_contents(base_path('.env.ci'));

        foreach (self::FIXTURE_ENV_KEYS as $key) {
            $this->assertStringNotContainsString(
                "{$key}=true",
                $ci,
                "{$key} is enabled in .env.ci. A pipeline that passes only because a fake result lane was available proves nothing."
            );
        }
    }

    #[Test]
    public function the_production_template_never_enables_a_fixture_lottery_lane(): void
    {
        $this->assertFileExists(base_path('.env.production.example'));

        $prod = (string) file_get_contents(base_path('.env.production.example'));

        foreach (self::FIXTURE_ENV_KEYS as $key) {
            $this->assertMatchesRegularExpression(
                '/^'.preg_quote($key, '/').'=false$/m',
                $prod,
                "{$key} must be explicitly false in the production template."
            );
        }
    }

    #[Test]
    public function fixture_lanes_are_force_disabled_when_the_app_env_is_production(): void
    {
        // The configs resolve APP_ENV directly, so the guard holds even if a
        // production .env sets the flag to true by mistake.
        foreach (['national', 'weekly', 'bingo', 'pcso'] as $lane) {
            $source = (string) file_get_contents(config_path("{$lane}_lottery.php"));

            $this->assertStringContainsString(
                "env('APP_ENV') === 'production'",
                $source,
                "config/{$lane}_lottery.php does not force the fixture lane off in production."
            );
        }
    }

    #[Test]
    public function no_official_lane_falls_through_to_fixture_data(): void
    {
        foreach (self::LANES as $lane) {
            $this->assertNotTrue(
                config("{$lane}.sources.fall_through_to_fixture"),
                "{$lane} would answer with fixture data when the official source fails."
            );
        }
    }

    #[Test]
    public function no_result_importer_defaults_to_the_fixture_provider(): void
    {
        // A cron entry or a hurried operator running the importer with no
        // arguments must target the official source and fail closed, never
        // fabricate a result into a real draw.
        $importers = glob(app_path('Console/Commands/Import*LotteryResults.php')) ?: [];

        $this->assertNotEmpty($importers, 'No lane importers were found to check.');

        foreach ($importers as $path) {
            $source = (string) file_get_contents($path);

            $this->assertStringNotContainsString(
                '{--provider=fixture',
                $source,
                basename($path).' defaults to the fixture provider. A bare run would write fabricated results into a real draw.'
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Environment provisioning
    // ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function the_stock_install_path_never_creates_an_env_from_the_example(): void
    {
        /** @var array<string, mixed> $composer */
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);

        $hooks = [
            'post-root-package-install',
            'post-install-cmd',
            'post-create-project-cmd',
            'post-update-cmd',
            'post-autoload-dump',
        ];

        foreach ($hooks as $hook) {
            $lines = (array) ($composer['scripts'][$hook] ?? []);

            foreach ($lines as $line) {
                $this->assertDoesNotMatchRegularExpression(
                    '/copy\(\s*[\'"]\.env\.example[\'"]/',
                    (string) $line,
                    "composer scripts.{$hook} copies .env.example into .env. A deployment must never derive its environment from the development template."
                );
            }
        }
    }

    #[Test]
    public function continuous_integration_never_copies_the_development_template(): void
    {
        foreach ((glob(base_path('.github/workflows/*.yml')) ?: []) as $workflow) {
            $contents = (string) file_get_contents($workflow);

            $this->assertDoesNotMatchRegularExpression(
                '/cp\s+\.env\.example\s+\.env/',
                $contents,
                basename($workflow).' copies .env.example into .env. CI must use the committed .env.ci instead.'
            );
        }
    }

    #[Test]
    public function the_production_template_ships_no_credential_values(): void
    {
        $prod = (string) file_get_contents(base_path('.env.production.example'));

        preg_match_all(
            '/^([A-Z0-9_]*(?:TOKEN|SECRET|KEY|PASSWORD|SALT))=(.+)$/m',
            $prod,
            $matches,
            PREG_SET_ORDER
        );

        $leaked = array_map(static fn (array $m): string => $m[1], $matches);

        $this->assertSame(
            [],
            $leaked,
            'These credentials carry a value in .env.production.example: '.implode(', ', $leaked)
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Boot-time guard
    // ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function a_safe_production_configuration_reports_no_violations(): void
    {
        config([
            'app.debug' => false,
            'session.secure' => true,
            'session.encrypt' => true,
            'session.driver' => 'redis',
            'queue.default' => 'redis',
            'sanctum.expiration' => 1440,
            'national_lottery.sources.fixture.enabled' => false,
            'weekly_lottery.sources.fixture.enabled' => false,
            'bingo_lottery.sources.fixture.enabled' => false,
            'pcso_lottery.sources.fixture.enabled' => false,
            'finance.prize_payout.safety_mode' => 'DISABLED',
            'lottery.payouts.auto_process' => false,
        ]);

        $this->assertSame([], ProductionSafetyServiceProvider::violations());
    }

    #[Test]
    public function an_unsafe_production_configuration_is_reported_in_full(): void
    {
        config([
            'app.debug' => true,
            'session.secure' => false,
            'session.encrypt' => false,
            'session.driver' => 'file',
            'queue.default' => 'sync',
            'sanctum.expiration' => '',
            'national_lottery.sources.fixture.enabled' => true,
        ]);

        $violations = implode("\n", ProductionSafetyServiceProvider::violations());

        $this->assertStringContainsString('APP_DEBUG', $violations);
        $this->assertStringContainsString('SESSION_SECURE_COOKIE', $violations);
        $this->assertStringContainsString('SESSION_ENCRYPT', $violations);
        $this->assertStringContainsString('QUEUE_CONNECTION is sync', $violations);
        $this->assertStringContainsString('SANCTUM_TOKEN_EXPIRATION', $violations);
        $this->assertStringContainsString('national_lottery.sources.fixture.enabled', $violations);
    }

    #[Test]
    public function every_fixture_lane_is_reported_individually_when_enabled(): void
    {
        config([
            'app.debug' => false,
            'session.secure' => true,
            'session.encrypt' => true,
            'session.driver' => 'redis',
            'queue.default' => 'redis',
            'sanctum.expiration' => 1440,
            'national_lottery.sources.fixture.enabled' => true,
            'weekly_lottery.sources.fixture.enabled' => true,
            'bingo_lottery.sources.fixture.enabled' => true,
            'pcso_lottery.sources.fixture.enabled' => true,
        ]);

        $violations = ProductionSafetyServiceProvider::violations();

        $this->assertCount(
            4,
            $violations,
            'Each enabled fixture lane must be reported on its own line so the operator knows exactly which one to turn off.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Money movement safety gate
    // ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function an_unrecognised_payout_safety_mode_is_never_treated_as_safe(): void
    {
        $this->safeProductionConfig();

        // A typo must not fall through to "not LIVE, therefore fine".
        config(['finance.prize_payout.safety_mode' => 'LIVE_']);

        $violations = implode("\n", ProductionSafetyServiceProvider::violations());

        $this->assertStringContainsString('PRIZE_PAYOUT_SAFETY_MODE', $violations);
    }

    #[Test]
    public function live_payout_without_an_approval_threshold_is_refused(): void
    {
        $this->safeProductionConfig();

        config([
            'finance.prize_payout.safety_mode' => 'LIVE',
            'finance.prize_payout.require_approval_at_or_above' => '',
        ]);

        $violations = implode("\n", ProductionSafetyServiceProvider::violations());

        $this->assertStringContainsString('PRIZE_PAYOUT_REQUIRE_APPROVAL_AT', $violations);
    }

    #[Test]
    public function automated_payout_disagreeing_with_the_safety_switch_is_refused(): void
    {
        $this->safeProductionConfig();

        // auto_process on, but the safety switch says money does not move.
        config([
            'lottery.payouts.auto_process' => true,
            'finance.prize_payout.safety_mode' => 'DISABLED',
        ]);

        $violations = implode("\n", ProductionSafetyServiceProvider::violations());

        $this->assertStringContainsString('auto_process', $violations);
    }

    #[Test]
    public function a_correctly_gated_live_payout_configuration_is_accepted(): void
    {
        $this->safeProductionConfig();

        config([
            'finance.prize_payout.safety_mode' => 'LIVE',
            'finance.prize_payout.require_approval_at_or_above' => '1000000.00',
            'lottery.payouts.auto_process' => true,
        ]);

        $this->assertSame(
            [],
            ProductionSafetyServiceProvider::violations(),
            'A deliberately commissioned live payout with an approval threshold must be allowed.'
        );
    }

    /**
     * The baseline every money-gate test starts from: everything else safe,
     * so the assertion can only be about the payout switch.
     */
    private function safeProductionConfig(): void
    {
        config([
            'app.debug' => false,
            'session.secure' => true,
            'session.encrypt' => true,
            'session.driver' => 'redis',
            'queue.default' => 'redis',
            'sanctum.expiration' => 1440,
            'national_lottery.sources.fixture.enabled' => false,
            'weekly_lottery.sources.fixture.enabled' => false,
            'bingo_lottery.sources.fixture.enabled' => false,
            'pcso_lottery.sources.fixture.enabled' => false,
            'finance.prize_payout.safety_mode' => 'DISABLED',
            'lottery.payouts.auto_process' => false,
        ]);
    }
}
