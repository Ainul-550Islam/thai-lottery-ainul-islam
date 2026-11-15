<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\Exceptions\SettlementSimulationException;
use App\Services\Draw\DrawSettlementSimulationService;
use ReflectionClass;
use Tests\TestCase;

/**
 * The settlement SIMULATION must never run against a production database.
 *
 * It marks bets Won/Lost and moves the draw to the terminal Settled state without
 * crediting a wallet or creating a payout row, and the real prize settlement only
 * replays a draw that is already Settled. Run on real bets it would close the draw
 * with nobody paid. The behaviour outside production stays covered by
 * SettlementSimulationTest.
 */
final class SettlementSimulationProductionGuardTest extends TestCase
{
    public function test_simulation_refuses_in_production_before_touching_anything(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');

        // Built WITHOUT its constructor on purpose. No lifecycle service, validator or
        // database is available to it, so the only way settle() can end in this
        // exception is the refusal itself, before any work. If the guard is ever moved
        // below the first use of a dependency, this test errors instead of passing.
        $service = (new ReflectionClass(DrawSettlementSimulationService::class))->newInstanceWithoutConstructor();

        try {
            $service->settle(1);
            self::fail('The simulation must refuse to settle in production.');
        } catch (SettlementSimulationException $exception) {
            self::assertStringContainsString('never runs in production', $exception->getMessage());
            self::assertStringContainsString('draw 1', $exception->getMessage());
        }
    }

    public function test_the_refusal_names_the_draw_and_has_a_stable_code(): void
    {
        self::assertSame(
            'SETTLEMENT_SIMULATION_FORBIDDEN_IN_PRODUCTION',
            SettlementSimulationException::CODE_SIMULATION_FORBIDDEN_IN_PRODUCTION,
        );

        self::assertStringContainsString('draw 7', SettlementSimulationException::forbiddenInProduction(7)->getMessage());
    }
}
