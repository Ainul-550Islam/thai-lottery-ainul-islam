<?php

declare(strict_types=1);

namespace Tests\Feature\Risk;

use App\DTOs\Betting\BulkBetSelectionData;
use App\Enums\BetType;
use App\Exceptions\RiskConfigurationException;
use App\Models\NumberLimit;
use App\Services\Betting\BulkBetService;
use App\Services\Risk\NumberLimitProvisioningService;
use Tests\Feature\Api\V1\ApiPurchaseTestCase;

/**
 * Release-blocking: a draw that is open must be bettable.
 *
 * NumberLimitEngine refuses any bet for which no number_limits row exists on the
 * exact (draw_id, bet_type, number) triple. Nothing in the codebase created those
 * rows, so every bet on every market was refused and the product could not take a
 * single bet. These tests pin the provisioning step that closes that gap, and -
 * most importantly - prove end to end that a purchase against a provisioned draw
 * actually succeeds.
 */
final class NumberLimitProvisioningTest extends ApiPurchaseTestCase
{
    /**
     * The aggregate stake ceiling has no default on purpose, so every test here
     * states it explicitly rather than depending on an ambient value.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('risk.exposure.max_stake_per_number', '100000.00');
    }

    public function test_an_unprovisioned_draw_refuses_every_bet(): void
    {
        $fixture = $this->fixture();

        $report = app(BulkBetService::class)->purchase(
            (int) $fixture['user']->id,
            (int) $fixture['draw']->id,
            [BulkBetSelectionData::fromRequestArray([
                'market' => '3d_direct',
                'number' => '123',
                'stake' => '20.00',
            ])],
            'unprovisioned-draw-key',
        );

        self::assertSame(1, $report['refused']);
        self::assertSame(0, $report['purchased']);
        self::assertStringContainsString(
            'No number limit row exists',
            (string) $report['items'][0]['reason'],
        );
    }

    public function test_provisioning_writes_the_full_number_space_for_every_bet_type(): void
    {
        $fixture = $this->fixture();
        $service = app(NumberLimitProvisioningService::class);

        $report = $service->provision($fixture['draw']);

        self::assertSame(100, $report['written'][BetType::TwoD->value]);
        self::assertSame(1000, $report['written'][BetType::ThreeD->value]);
        self::assertSame(1000, $report['written'][BetType::Tod->value]);
        self::assertSame(10, $report['written'][BetType::Run->value]);
        self::assertSame(2110, $report['total']);

        self::assertTrue($service->isFullyProvisioned($fixture['draw']));
    }

    public function test_numbers_are_zero_padded_to_the_width_the_engine_looks_up(): void
    {
        $fixture = $this->fixture();
        app(NumberLimitProvisioningService::class)->provision($fixture['draw']);

        // The engine looks up '007', never '7'. A row written as '7' is a row
        // that is never found.
        foreach ([
            [BetType::ThreeD->value, '007'],
            [BetType::ThreeD->value, '000'],
            [BetType::TwoD->value, '07'],
            [BetType::Run->value, '7'],
        ] as [$betType, $number]) {
            self::assertTrue(
                NumberLimit::query()
                    ->where('draw_id', (int) $fixture['draw']->id)
                    ->where('bet_type', $betType)
                    ->where('number', $number)
                    ->exists(),
                sprintf('Expected a %s capacity row for number %s.', $betType, $number),
            );
        }
    }

    public function test_a_purchase_against_a_provisioned_draw_succeeds(): void
    {
        $fixture = $this->fixture();
        app(NumberLimitProvisioningService::class)->provision($fixture['draw']);

        $report = app(BulkBetService::class)->purchase(
            (int) $fixture['user']->id,
            (int) $fixture['draw']->id,
            [BulkBetSelectionData::fromRequestArray([
                'market' => '3d_direct',
                'number' => '123',
                'stake' => '20.00',
            ])],
            'provisioned-draw-key',
        );

        self::assertSame(
            0,
            $report['refused'],
            'Refusal reason: '.(string) ($report['items'][0]['reason'] ?? 'none'),
        );
        self::assertSame(1, $report['purchased']);
        self::assertSame('20.00', $report['total_charged']);
    }

    public function test_provisioning_is_idempotent_and_preserves_accumulated_exposure(): void
    {
        $fixture = $this->fixture();
        $service = app(NumberLimitProvisioningService::class);

        $service->provision($fixture['draw']);

        $row = NumberLimit::query()
            ->where('draw_id', (int) $fixture['draw']->id)
            ->where('bet_type', BetType::ThreeD->value)
            ->where('number', '123')
            ->firstOrFail();

        // Simulate exposure the draw has already accumulated.
        $row->forceFill(['current_amount' => '750.00'])->save();

        $second = $service->provision($fixture['draw']);

        self::assertSame(2110, $second['total']);
        self::assertSame(
            2110,
            NumberLimit::query()->where('draw_id', (int) $fixture['draw']->id)->count(),
            'Re-running must upsert, never duplicate.',
        );

        $row->refresh();
        self::assertSame('750.00', (string) $row->current_amount);
    }

    public function test_provisioning_refuses_to_invent_a_stake_ceiling(): void
    {
        config()->set('risk.exposure.max_stake_per_number', null);

        $fixture = $this->fixture();

        $this->expectException(RiskConfigurationException::class);

        app(NumberLimitProvisioningService::class)->provision($fixture['draw']);
    }

    public function test_the_command_provisions_and_reports_state(): void
    {
        $fixture = $this->fixture();

        $this->artisan('risk:provision-number-limits', ['draw' => (int) $fixture['draw']->id])
            ->assertExitCode(0);

        self::assertTrue(
            app(NumberLimitProvisioningService::class)->isFullyProvisioned($fixture['draw'])
        );

        $this->artisan('risk:provision-number-limits', [
            'draw' => (int) $fixture['draw']->id,
            '--check' => true,
        ])->assertExitCode(0);
    }
}
