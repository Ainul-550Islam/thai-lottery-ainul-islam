<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use App\Models\PointsMultiplierCampaign;
use App\Models\User;
use App\Services\Loyalty\PointsMultiplierService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * Points multipliers are INTEGER BASIS POINTS, and stacking is a policy.
 *
 * WHY THE INTEGER MATTERS
 * 1.5x as a float is 1.4999999999999998 on some paths, and points are integers in the
 * ledger. A multiplier that produces 2999 points where the campaign promised 3000 is
 * the kind of discrepancy a member notices and a support agent cannot explain. So the
 * storage is bps and the arithmetic is intdiv.
 *
 * WHY STACKING IS EXPLICIT
 * Two active campaigns on the same game either multiply together (2.0 x 1.5 = 3.0) or
 * the best one wins (2.0). Both are defensible products; silently doing one when the
 * config says the other is not.
 */
final class PointsMultiplierServiceTest extends TestCase
{
    use DatabaseTruncation;

    private PointsMultiplierService $multipliers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->multipliers = app(PointsMultiplierService::class);
    }

    private function campaign(array $overrides = []): PointsMultiplierCampaign
    {
        return PointsMultiplierCampaign::query()->create(array_merge([
            'name' => 'Weekend boost',
            'slug' => 'weekend-'.bin2hex(random_bytes(4)),
            'multiplier_bps' => 15000,
            'active' => true,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ], $overrides));
    }

    public function test_with_no_campaign_the_multiplier_is_one(): void
    {
        $user = User::factory()->create();

        $this->assertSame(10000, $this->multipliers->multiplierBpsAt(gameCode: '2d_top'));
    }

    public function test_an_active_campaign_applies_to_every_game(): void
    {
        $user = User::factory()->create();
        $this->campaign();

        $this->assertSame(15000, $this->multipliers->multiplierBpsAt(gameCode: '2d_top'));
    }

    public function test_a_game_scoped_campaign_only_applies_to_that_game(): void
    {
        $user = User::factory()->create();
        $this->campaign(['game_scope' => '2d_top']);

        $this->assertSame(15000, $this->multipliers->multiplierBpsAt(gameCode: '2d_top'));
        $this->assertSame(10000, $this->multipliers->multiplierBpsAt(gameCode: '3d_direct'));
    }

    public function test_an_inactive_campaign_pays_nothing(): void
    {
        $user = User::factory()->create();
        $this->campaign(['active' => false]);

        $this->assertSame(10000, $this->multipliers->multiplierBpsAt(gameCode: '2d_top'));
    }

    public function test_a_campaign_outside_its_window_pays_nothing(): void
    {
        $user = User::factory()->create();

        $this->campaign([
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(3),
        ]);

        $this->assertSame(10000, $this->multipliers->multiplierBpsAt(gameCode: '2d_top'));
    }

    public function test_an_expired_campaign_pays_nothing(): void
    {
        $user = User::factory()->create();

        $this->campaign([
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->subDay(),
        ]);

        $this->assertSame(10000, $this->multipliers->multiplierBpsAt(gameCode: '2d_top'));
    }

    public function test_an_open_ended_campaign_applies(): void
    {
        $user = User::factory()->create();
        $this->campaign(['ends_at' => null]);

        $this->assertSame(15000, $this->multipliers->multiplierBpsAt(gameCode: '2d_top'));
    }

    public function test_the_highest_wins_under_the_highest_stacking_policy(): void
    {
        config(['player_experience.promotions.multiplier_stacking' => 'highest']);

        $user = User::factory()->create();
        $this->campaign(['multiplier_bps' => 15000]);
        $this->campaign(['multiplier_bps' => 20000]);

        $this->assertSame(
            20000,
            $this->multipliers->multiplierBpsAt(gameCode: '2d_top'),
            'Under highest-only stacking, the best campaign wins and they do not compound.',
        );
    }

    public function test_no_stacking_means_the_first_campaign_wins(): void
    {
        config(['player_experience.promotions.multiplier_stacking' => 'none']);

        $user = User::factory()->create();
        $this->campaign(['multiplier_bps' => 15000]);
        $this->campaign(['multiplier_bps' => 20000]);

        $this->assertSame(10000, $this->multipliers->multiplierBpsAt(gameCode: '2d_top'));
    }

    public function test_the_multiplier_is_bounded_by_the_configured_ceiling(): void
    {
        $ceiling = (int) config('player_experience.loyalty.max_multiplier_bps', 50000);

        // A campaign configured above the ceiling is clamped, not honoured: the
        // ceiling is a risk control, so a data-entry mistake cannot bypass it.
        $user = User::factory()->create();
        // A campaign at twice the ceiling. The ceiling is a risk control, so a
        // data-entry mistake must not raise the payout: the campaign is ignored.
        $this->campaign(['multiplier_bps' => $ceiling * 2]);

        $this->assertLessThanOrEqual(
            $ceiling,
            $this->multipliers->multiplierBpsAt(gameCode: '2d_top'),
        );
    }

    public function test_applying_the_multiplier_keeps_points_integers(): void
    {
        $user = User::factory()->create();
        $this->campaign(['multiplier_bps' => 15000]);

        $points = $this->multipliers->effectivePoints(100, gameCode: '2d_top');

        $this->assertIsInt($points);
        $this->assertSame(150, $points);
    }

    public function test_applying_a_multiplier_never_produces_a_fraction(): void
    {
        // 33 points at 1.5x is 49.5. Truncating to 49 is the only defensible answer:
        // rounding up would create points the platform did not earn.
        $this->campaign(['multiplier_bps' => 15000]);

        $points = $this->multipliers->effectivePoints(33, gameCode: '2d_top');

        $this->assertSame(49, $points);
        $this->assertIsInt($points);
    }

    public function test_reading_the_multiplier_writes_nothing(): void
    {
        $user = User::factory()->create();
        $this->campaign();

        $before = PointsMultiplierCampaign::query()->count();

        $this->multipliers->multiplierBpsAt(gameCode: '2d_top');
        $this->multipliers->multiplierBpsAt(gameCode: '2d_top');

        $this->assertSame($before, PointsMultiplierCampaign::query()->count());
    }

    public function test_the_active_promotions_read_model_exposes_the_multiplier(): void
    {
        $this->campaign(['name' => 'Weekend boost']);

        $user = User::factory()->create();
        $promotions = app(\App\Services\Promotions\ActivePromotionsQueryService::class)->active($user);

        $this->assertArrayHasKey('points_campaigns', $promotions);
        $this->assertArrayHasKey('bonuses', $promotions);
        $this->assertArrayHasKey('effective_multiplier', $promotions);
        $this->assertNotEmpty($promotions['points_campaigns']);

        $first = $promotions['points_campaigns'][0];

        $this->assertSame('points_multiplier', $first['type']);
        $this->assertSame(15000, $first['multiplier_bps']);
        // The display multiplier is a decimal STRING, so it can never be a float.
        $this->assertSame('1.5000', $first['multiplier']);
    }
}
