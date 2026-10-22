<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Providers\ProductionSafetyServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * An explicit fixture rung must not bypass the independent production guard,
 * even if official mode is selected and fixture fall-through is disabled.
 */
final class GloFixturePrioritySafetyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.debug' => false,
            'session.secure' => true,
            'session.encrypt' => true,
            'session.driver' => 'redis',
            'cache.default' => 'redis',
            'queue.default' => 'redis',
            'sanctum.expiration' => 1440,
            'national_lottery.sources.fixture.enabled' => false,
            'weekly_lottery.sources.fixture.enabled' => false,
            'bingo_lottery.sources.fixture.enabled' => false,
            'pcso_lottery.sources.fixture.enabled' => false,
            'finance.prize_payout.safety_mode' => 'DISABLED',
            'lottery.payouts.auto_process' => false,
            'glo.official_source.mode' => 'official',
            'glo.sources.fall_through_to_fixture' => false,
            'glo.sources.priority' => [],
        ]);
    }

    #[DataProvider('priorities')]
    public function test_production_guard_recognizes_the_same_fixture_names_as_the_chain(
        mixed $priority,
        bool $mustRefuse,
    ): void {
        config(['glo.sources.priority' => $priority]);
        $violations = ProductionSafetyServiceProvider::violations();

        if ($mustRefuse) {
            $this->assertCount(1, $violations);
            $this->assertStringContainsString('glo.sources.priority contains fixture', $violations[0]);
        } else {
            $this->assertSame([], $violations);
        }

        $this->assertSame('DISABLED', config('finance.prize_payout.safety_mode'));
        $this->assertFalse(config('lottery.payouts.auto_process'));
    }

    /**
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function priorities(): array
    {
        return [
            'single official source' => [['official'], false],
            'mode-only official selection' => [[], false],
            'case-normalized official source' => [[' OFFICIAL '], false],
            'explicit fixture fallback' => [['official', 'fixture'], true],
            'fixture as first choice' => [['fixture', 'official'], true],
            'case-normalized fixture name' => [['official', ' FiXtUrE '], true],
            'scalar fixture configuration' => ['fixture', true],
            'unknown non-string values are not interpreted as providers' => [[123, null, false], false],
        ];
    }

    public function test_production_boot_refuses_a_fixture_priority_before_serving_requests(): void
    {
        config(['glo.sources.priority' => ['official', 'fixture']]);
        $originalEnvironment = $this->app['env'];
        $this->app['env'] = 'production';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('glo.sources.priority contains fixture');

        try {
            (new ProductionSafetyServiceProvider($this->app))->boot();
        } finally {
            $this->app['env'] = $originalEnvironment;
        }
    }
}
