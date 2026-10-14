<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\GloSourceState;
use App\Services\Lottery\BingoLotterySourceService;
use App\Services\Lottery\NationalLotterySourceService;
use App\Services\Lottery\PcsoLotterySourceService;
use App\Services\Lottery\WeeklyLotterySourceService;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * P1-27: Proves fixture sources are disabled by production default and cannot publish as live/official.
 */
final class FixturePublicationSafetyTest extends TestCase
{
    public function test_fixture_sources_default_to_disabled_in_configuration(): void
    {
        // phpunit.xml turns fixtures on for the test run because other suites
        // need them, so the runtime config cannot show the shipped default.
        // Check the shipped environment templates instead: a stock deployment
        // copies .env.example or .env.production.example.
        $keys = [
            'NATIONAL_LOTTERY_FIXTURE_ENABLED',
            'WEEKLY_LOTTERY_FIXTURE_ENABLED',
            'BINGO_LOTTERY_FIXTURE_ENABLED',
            'PCSO_LOTTERY_FIXTURE_ENABLED',
        ];

        foreach (['.env.example', '.env.production.example'] as $template) {
            $contents = (string) file_get_contents(base_path($template));

            foreach ($keys as $key) {
                $this->assertMatchesRegularExpression(
                    '/^'.$key.'=false\s*$/m',
                    $contents,
                    "{$template} must ship {$key}=false",
                );
            }
        }

        // The config fallback must also be false when the variable is absent.
        foreach (['national_lottery', 'weekly_lottery', 'bingo_lottery', 'pcso_lottery'] as $file) {
            $source = (string) file_get_contents(config_path($file.'.php'));
            $this->assertMatchesRegularExpression(
                "/env\\('[A-Z_]+_FIXTURE_ENABLED',\\s*false\\)/",
                $source,
                "config/{$file}.php must default the fixture flag to false",
            );
        }
    }

    public function test_fixture_provider_never_evaluates_to_official_source(): void
    {
        $services = [
            app(NationalLotterySourceService::class),
            app(WeeklyLotterySourceService::class),
            app(BingoLotterySourceService::class),
            app(PcsoLotterySourceService::class),
        ];

        foreach ($services as $service) {
            $state = $service->stateForProvider('fixture', true);
            $this->assertNotSame(GloSourceState::OfficialSourceVerified, $state);
            $this->assertNotSame(GloSourceState::OfficialSourceConfigured, $state);
        }
    }

    public function test_fixtures_are_hard_stopped_in_production_environment(): void
    {
        $this->app['env'] = 'production';

        Config::set('national_lottery.sources.fixture.enabled', true);
        Config::set('weekly_lottery.sources.fixture.enabled', true);
        Config::set('bingo_lottery.sources.fixture.enabled', true);
        Config::set('pcso_lottery.sources.fixture.enabled', true);

        $services = [
            app(NationalLotterySourceService::class),
            app(WeeklyLotterySourceService::class),
            app(BingoLotterySourceService::class),
            app(PcsoLotterySourceService::class),
        ];

        foreach ($services as $service) {
            $this->assertFalse($service->fixturesEnabled(), 'Fixtures must be strictly disabled when environment is production.');
            $state = $service->stateForProvider('fixture', true);
            $this->assertSame(GloSourceState::NotConfigured, $state);
        }
    }
}
