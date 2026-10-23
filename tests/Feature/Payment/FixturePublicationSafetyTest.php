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
        $code = <<<'PHP'
require %s;
$files = [
    'national' => require %s,
    'weekly' => require %s,
    'bingo' => require %s,
    'pcso' => require %s,
];
echo json_encode([
    'national' => (bool) ($files['national']['sources']['fixture']['enabled'] ?? true),
    'weekly' => (bool) ($files['weekly']['sources']['fixture']['enabled'] ?? true),
    'bingo' => (bool) ($files['bingo']['sources']['fixture']['enabled'] ?? true),
    'pcso' => (bool) ($files['pcso']['sources']['fixture']['enabled'] ?? true),
]);
PHP;

        $script = sprintf(
            $code,
            var_export(base_path('vendor/autoload.php'), true),
            var_export(config_path('national_lottery.php'), true),
            var_export(config_path('weekly_lottery.php'), true),
            var_export(config_path('bingo_lottery.php'), true),
            var_export(config_path('pcso_lottery.php'), true),
        );

        $env = array_merge($_ENV, [
            'APP_ENV' => 'production',
            'NATIONAL_LOTTERY_FIXTURE_ENABLED' => 'true',
            'WEEKLY_LOTTERY_FIXTURE_ENABLED' => 'true',
            'BINGO_LOTTERY_FIXTURE_ENABLED' => 'true',
            'PCSO_LOTTERY_FIXTURE_ENABLED' => 'true',
        ]);

        $process = proc_open(
            [PHP_BINARY, '-r', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
            $env,
        );

        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(0, $exitCode);
        $decoded = json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);

        $this->assertFalse($decoded['national']);
        $this->assertFalse($decoded['weekly']);
        $this->assertFalse($decoded['bingo']);
        $this->assertFalse($decoded['pcso']);
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
