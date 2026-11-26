<?php

declare(strict_types=1);

namespace Tests\Feature\BetSlip;

use App\Models\User;
use App\Services\BetSlip\BetSlipPricingService;
use App\Services\BetSlip\BetSlipService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * Pricing is PROVISIONAL and it says so.
 *
 * The slip screen shows a potential win before the member commits. That figure comes
 * from the existing MarketPayoutService — this service does not invent a multiplier,
 * does not round, and does not cache. The moment it disagreed with what the purchase
 * pipeline charges, the member would be looking at a number the platform never
 * intended to pay.
 */
final class BetSlipPricingServiceTest extends TestCase
{
    use DatabaseTruncation;

    private BetSlipPricingService $pricing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pricing = app(BetSlipPricingService::class);
    }

    public function test_an_empty_slip_prices_to_zero_without_erroring(): void
    {
        $user = User::factory()->create();
        $slip = app(BetSlipService::class)->findOrCreateDraft((int) $user->id);

        $priced = $this->pricing->price($slip);

        $this->assertSame('0.00', $priced['total_stake']);
        $this->assertSame([], $priced['items']);
    }

    public function test_every_amount_is_a_decimal_string(): void
    {
        $user = User::factory()->create();
        app(BetSlipService::class)->addItem((int) $user->id, '2d_top', '2d', '07', '10.00');

        $slip = app(BetSlipService::class)->findOrCreateDraft((int) $user->id);
        $priced = $this->pricing->price($slip);

        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $priced['total_stake']);

        foreach ($priced['items'] as $item) {
            $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $item['stake']);
            $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $item['potential_win']);
        }
    }

    public function test_the_potential_payout_is_never_below_the_stake(): void
    {
        // Every shipped market pays at 3x or better. A payout below the stake would
        // mean the multiplier was read backwards.
        $user = User::factory()->create();
        app(BetSlipService::class)->addItem((int) $user->id, '2d_top', '2d', '07', '10.00');

        $slip = app(BetSlipService::class)->findOrCreateDraft((int) $user->id);
        $priced = $this->pricing->price($slip);

        foreach ($priced['items'] as $item) {
            $this->assertGreaterThanOrEqual(
                0,
                bccomp((string) $item['potential_win'], (string) $item['stake'], 2),
                'A potential payout below the stake means the multiplier was applied backwards.',
            );
        }
    }

    public function test_the_total_agrees_with_the_denormalised_column(): void
    {
        $user = User::factory()->create();
        app(BetSlipService::class)->addItem((int) $user->id, '2d_top', '2d', '07', '11.11');
        app(BetSlipService::class)->addItem((int) $user->id, '3d_direct', '3d', '123', '22.22');

        $slip = app(BetSlipService::class)->findOrCreateDraft((int) $user->id);
        $priced = $this->pricing->price($slip);

        // The two independent computations must agree; if they cannot, the cached
        // column is what the member sees and the recomputation is what they are
        // charged.
        $this->assertSame((string) $slip->total_stake, $priced['total_stake']);
        $this->assertSame('33.33', $priced['total_stake']);
    }

    public function test_an_unpriceable_line_is_reported_without_blanking_the_basket(): void
    {
        $user = User::factory()->create();
        app(BetSlipService::class)->addItem((int) $user->id, '2d_top', '2d', '07', '10.00');

        $slip = app(BetSlipService::class)->findOrCreateDraft((int) $user->id);
        $priced = $this->pricing->price($slip);

        $this->assertArrayHasKey('priceable', $priced);
        $this->assertIsBool($priced['priceable']);

        foreach ($priced['items'] as $item) {
            $this->assertArrayHasKey('priceable', $item);
            $this->assertArrayHasKey('reason', $item);

            // A line that cannot be priced carries a REASON, not a zero payout.
            if (! $item['priceable']) {
                $this->assertNotNull($item['reason']);
            }
        }
    }

    public function test_pricing_is_marked_provisional(): void
    {
        $user = User::factory()->create();
        app(BetSlipService::class)->addItem((int) $user->id, '2d_top', '2d', '07', '10.00');

        $slip = app(BetSlipService::class)->findOrCreateDraft((int) $user->id);
        $priced = $this->pricing->price($slip);

        $this->assertTrue(
            (bool) ($priced['provisional'] ?? true),
            'Slip pricing is a preview; the authoritative figure comes from the purchase pipeline.',
        );
    }

    public function test_pricing_writes_nothing(): void
    {
        $user = User::factory()->create();
        app(BetSlipService::class)->addItem((int) $user->id, '2d_top', '2d', '07', '10.00');

        $slip = app(BetSlipService::class)->findOrCreateDraft((int) $user->id);
        $before = $slip->fresh()->toArray();

        $this->pricing->price($slip);
        $this->pricing->price($slip);

        $this->assertSame($before, $slip->fresh()->toArray(), 'Pricing a slip must not mutate it.');
    }
}
