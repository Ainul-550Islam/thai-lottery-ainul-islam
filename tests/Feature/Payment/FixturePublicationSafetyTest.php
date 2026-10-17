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
        // phpunit.xml force-enables every fixture lane for the suites that
        // exercise them, so "the default" can only be read with those
        // variables absent. Clear them, rebuild the application so the
        // config files re-read env(), and restore everything afterwards
        // so no later suite inherits the cleared state.
        $keys = [
            'NATIONAL_LOTTERY_FIXTURE_ENABLED',
            'WEEKLY_LOTTERY_FIXTURE_ENABLED',
            'BINGO_LOTTERY_FIXTURE_ENABLED',
            'PCSO_LOTTERY_FIXTURE_ENABLED',
        ];

        $saved = [];

        foreach ($keys as $key) {
            $saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }

        try {
            $this->refreshApplication();

            $this->assertFalse((bool) config('national_lottery.sources.fixture.enabled', true));
            $this->assertFalse((bool) config('weekly_lottery.sources.fixture.enabled', true));
            $this->assertFalse((bool) config('bingo_lottery.sources.fixture.enabled', true));
            $this->assertFalse((bool) config('pcso_lottery.sources.fixture.enabled', true));
        } finally {
            foreach ($keys as $key) {
                [$env, $server, $put] = $saved[$key];

                if ($env === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $env;
                }

                if ($server === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $server;
                }

                $put === false ? putenv($key) : putenv("{$key}={$put}");
            }

            $this->refreshApplication();
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
