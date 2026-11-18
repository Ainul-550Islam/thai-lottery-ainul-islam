<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use App\Models\LoyaltyPointLedger;
use App\Models\PointsMultiplierCampaign;
use App\Models\User;
use App\Services\Loyalty\LoyaltyPointsService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * Batch 3 edited LoyaltyPointsService::awardForSettledWager() (one call site changed
 * from calculatePoints() to effectivePoints(), metadata wrapped in array_merge()).
 * This file pins the promise that made that edit acceptable: with no campaign, every
 * result is what it was before.
 *
 * THE FIGURES ARE GOLDEN, NOT DERIVED
 * Each expected value below is written out as a literal. Recomputing the expectation
 * with the same formula the service uses would prove nothing: if the formula changed,
 * both sides would change together and the test would stay green. The configuration is
 * set explicitly in setUp() so the literals do not depend on a deployment's values.
 *
 *   unit stake per point = 10.00
 *   multipliers           = bronze 1.00, silver 1.25, gold 1.50
 *   points = trunc( (stake / 10.00, 4 dp) * multiplier )
 */
final class LoyaltyPointsRegressionTest extends TestCase
{
    use DatabaseTruncation;

    private LoyaltyPointsService $loyalty;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'conversion_tooling.loyalty.enabled' => true,
            'conversion_tooling.loyalty.unit_stake_per_point' => '10.00',
            'conversion_tooling.loyalty.tier_multipliers' => [
                'bronze' => '1.00',
                'silver' => '1.25',
                'gold' => '1.50',
            ],
            'player_experience.promotions.multiplier_stacking' => 'highest',
        ]);

        $this->loyalty = app(LoyaltyPointsService::class);
    }

    /**
     * @return array<string, array{0:string, 1:string, 2:int}>
     */
    public static function goldenPoints(): array
    {
        return [
            'zero stake' => ['0.00', 'bronze', 0],
            'below one unit' => ['9.99', 'bronze', 0],
            'one unit' => ['10.00', 'bronze', 1],
            'just under ten units' => ['99.99', 'bronze', 9],
            'ten units' => ['100.00', 'bronze', 10],
            'silver truncates 12.5 to 12' => ['100.00', 'silver', 12],
            'gold truncates 8.3325 to 8' => ['55.55', 'gold', 8],
            'large stake' => ['1234.56', 'bronze', 123],
            'large stake gold' => ['1000.00', 'gold', 150],
            'negative stake' => ['-5.00', 'bronze', 0],
            'unknown tier uses 1.00' => ['100.00', 'platinum-unknown', 10],
        ];
    }

    /**
     * @dataProvider goldenPoints
     */
    public function test_calculate_points_returns_the_historic_figure(string $stake, string $tier, int $expected): void
    {
        $this->assertSame($expected, $this->loyalty->calculatePoints($stake, $tier));
    }

    /**
     * @dataProvider goldenPoints
     */
    public function test_with_no_campaign_effective_points_equals_calculate_points(string $stake, string $tier, int $expected): void
    {
        $this->assertSame($expected, $this->loyalty->effectivePoints($stake, $tier));
        $this->assertSame(
            $this->loyalty->calculatePoints($stake, $tier),
            $this->loyalty->effectivePoints($stake, $tier),
        );
    }

    public function test_an_earn_with_no_campaign_writes_the_historic_ledger_row(): void
    {
        $user = User::factory()->create();

        $row = $this->loyalty->awardForSettledWager($user, '100.00', 'regress-earn-0001', 'silver', 'bet', '42');

        $this->assertInstanceOf(LoyaltyPointLedger::class, $row);
        $this->assertSame(12, (int) $row->points);
        $this->assertSame(12, (int) $row->balance_after);
        $this->assertSame(LoyaltyPointsService::ENTRY_EARN, $row->entry_type);
        $this->assertSame('regress-earn-0001', $row->idempotency_key);

        $metadata = (array) $row->metadata;
        $keys = array_keys($metadata);
        sort($keys);

        // Exactly the three keys that existed before Batch 3: no campaign stamp.
        $this->assertSame(['multiplier', 'stake_amount', 'tier_key'], $keys);
        $this->assertSame('100.00', $metadata['stake_amount']);
        $this->assertSame('silver', $metadata['tier_key']);
        $this->assertSame('1.25', $metadata['multiplier']);
        $this->assertArrayNotHasKey(
            (string) config('player_experience.loyalty.multiplier_audit_key', 'points_multiplier_key'),
            $metadata,
        );
    }

    public function test_the_balance_accumulates_exactly_as_before(): void
    {
        $user = User::factory()->create();

        $this->loyalty->awardForSettledWager($user, '100.00', 'regress-bal-0001', 'bronze');
        $second = $this->loyalty->awardForSettledWager($user, '50.00', 'regress-bal-0002', 'bronze');

        $this->assertSame(5, (int) $second->points);
        $this->assertSame(15, (int) $second->balance_after);
        $this->assertSame(15, $this->loyalty->currentBalance($user));
    }

    public function test_the_same_idempotency_key_returns_the_same_row_and_writes_once(): void
    {
        $user = User::factory()->create();

        $a = $this->loyalty->awardForSettledWager($user, '100.00', 'regress-idem-0001', 'bronze');
        $b = $this->loyalty->awardForSettledWager($user, '100.00', 'regress-idem-0001', 'bronze');

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, LoyaltyPointLedger::query()->where('user_id', $user->id)->count());
        $this->assertSame(10, $this->loyalty->currentBalance($user));
    }

    public function test_a_stake_below_one_unit_earns_nothing_and_writes_nothing(): void
    {
        $user = User::factory()->create();

        $this->assertNull($this->loyalty->awardForSettledWager($user, '9.99', 'regress-none-0001', 'bronze'));
        $this->assertSame(0, LoyaltyPointLedger::query()->where('user_id', $user->id)->count());
    }

    public function test_the_programme_switch_still_disables_earning(): void
    {
        config(['conversion_tooling.loyalty.enabled' => false]);
        $user = User::factory()->create();

        $this->assertNull($this->loyalty->awardForSettledWager($user, '100.00', 'regress-off-0001', 'bronze'));
    }

    public function test_an_active_campaign_does_multiply_and_is_stamped_on_the_row(): void
    {
        PointsMultiplierCampaign::query()->create([
            'name' => 'Double points',
            'slug' => 'regress-double-'.bin2hex(random_bytes(3)),
            'multiplier_bps' => 20000,
            'active' => true,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);

        $user = User::factory()->create();

        $row = $this->loyalty->awardForSettledWager($user, '100.00', 'regress-camp-0001', 'bronze');

        $this->assertSame(20, (int) $row->points, '10 base points at 2.0x');

        $metadata = (array) $row->metadata;
        $key = (string) config('player_experience.loyalty.multiplier_audit_key', 'points_multiplier_key');

        $this->assertArrayHasKey($key, $metadata, 'The campaign must be auditable on the ledger row.');
        $this->assertSame(20000, (int) $metadata[$key]['multiplier_bps']);
    }

    public function test_an_expired_campaign_changes_nothing(): void
    {
        PointsMultiplierCampaign::query()->create([
            'name' => 'Yesterday',
            'slug' => 'regress-expired-'.bin2hex(random_bytes(3)),
            'multiplier_bps' => 30000,
            'active' => true,
            'starts_at' => now()->subDays(3),
            'ends_at' => now()->subDay(),
        ]);

        $this->assertSame(10, $this->loyalty->effectivePoints('100.00', 'bronze'));
    }

    public function test_stacking_none_restores_the_exact_old_result_even_with_a_campaign(): void
    {
        PointsMultiplierCampaign::query()->create([
            'name' => 'Ignored',
            'slug' => 'regress-none-'.bin2hex(random_bytes(3)),
            'multiplier_bps' => 20000,
            'active' => true,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);

        config(['player_experience.promotions.multiplier_stacking' => 'none']);

        $this->assertSame(
            $this->loyalty->calculatePoints('100.00', 'bronze'),
            $this->loyalty->effectivePoints('100.00', 'bronze'),
        );
    }
}
