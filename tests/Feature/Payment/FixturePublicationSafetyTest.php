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
        $this->assertFalse((bool) config('national_lottery.sources.fixture.enabled', true));
        $this->assertFalse((bool) config('weekly_lottery.sources.fixture.enabled', true));
        $this->assertFalse((bool) config('bingo_lottery.sources.fixture.enabled', true));
        $this->assertFalse((bool) config('pcso_lottery.sources.fixture.enabled', true));
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
