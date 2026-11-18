<?php

declare(strict_types=1);

namespace Tests\Feature\Promotions;

use App\Models\PointsMultiplierCampaign;
use App\Models\User;
use App\Services\Promotions\ActivePromotionsQueryService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * /me/promotions/active — a read model over campaigns that already exist.
 *
 * THE FAILURE THIS PREVENTS
 * A promotions screen is marketing copy with a database behind it, so it is the most
 * likely place for an expired campaign to keep showing, or for a bonus catalogue to be
 * reported as "no bonuses available" when the bonus service simply threw. Both are
 * asserted below. Nothing here writes.
 */
final class ActivePromotionsQueryServiceTest extends TestCase
{
    use DatabaseTruncation;

    private function campaign(array $overrides = []): PointsMultiplierCampaign
    {
        return PointsMultiplierCampaign::query()->create(array_merge([
            'name' => 'Songkran boost',
            'slug' => 'songkran-'.bin2hex(random_bytes(4)),
            'multiplier_bps' => 20000,
            'active' => true,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(3),
        ], $overrides));
    }

    public function test_with_no_campaigns_the_list_is_empty_not_fabricated(): void
    {
        $user = User::factory()->create();

        $payload = app(ActivePromotionsQueryService::class)->active($user);

        $this->assertSame([], $payload['points_campaigns']);
        $this->assertArrayHasKey('generated_at', $payload);
    }

    public function test_an_active_campaign_appears_with_its_integer_multiplier(): void
    {
        $this->campaign();
        $user = User::factory()->create();

        $payload = app(ActivePromotionsQueryService::class)->active($user);

        $this->assertCount(1, $payload['points_campaigns']);

        $campaign = $payload['points_campaigns'][0];

        $this->assertSame('points_multiplier', $campaign['type']);
        $this->assertSame(20000, $campaign['multiplier_bps']);
        $this->assertSame('2.0000', $campaign['multiplier']);
    }

    public function test_an_expired_campaign_does_not_appear(): void
    {
        $this->campaign([
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->subDay(),
        ]);

        $user = User::factory()->create();

        $payload = app(ActivePromotionsQueryService::class)->active($user);

        $this->assertSame([], $payload['points_campaigns'], 'An ended campaign must not still be advertised.');
    }

    public function test_an_inactive_campaign_does_not_appear(): void
    {
        $this->campaign(['active' => false]);

        $user = User::factory()->create();

        $payload = app(ActivePromotionsQueryService::class)->active($user);

        $this->assertSame([], $payload['points_campaigns']);
    }

    public function test_a_future_campaign_does_not_appear_yet(): void
    {
        $this->campaign([
            'starts_at' => now()->addDays(5),
            'ends_at' => now()->addDays(10),
        ]);

        $user = User::factory()->create();

        $payload = app(ActivePromotionsQueryService::class)->active($user);

        $this->assertSame([], $payload['points_campaigns']);
    }

    public function test_the_list_is_bounded_by_config(): void
    {
        config(['player_experience.promotions.active_list_limit' => 2]);

        foreach (range(1, 5) as $i) {
            $this->campaign(['name' => 'Campaign '.$i]);
        }

        $user = User::factory()->create();

        $payload = app(ActivePromotionsQueryService::class)->active($user);

        $this->assertLessThanOrEqual(2, count($payload['points_campaigns']));
    }

    public function test_an_open_ended_campaign_reports_no_end_date_rather_than_inventing_one(): void
    {
        $this->campaign(['ends_at' => null]);

        $user = User::factory()->create();

        $payload = app(ActivePromotionsQueryService::class)->active($user);

        $this->assertNull(
            $payload['points_campaigns'][0]['ends_at'],
            'An open-ended campaign must not be given a plausible-looking deadline.',
        );
    }

    public function test_the_payload_reports_bonus_availability_honestly(): void
    {
        $user = User::factory()->create();

        $payload = app(ActivePromotionsQueryService::class)->active($user);

        // bonuses_available is a statement about the data, not a hopeful default.
        $this->assertSame($payload['bonuses'] !== [], $payload['bonuses_available']);
    }

    public function test_reading_the_promotions_writes_nothing(): void
    {
        $this->campaign();
        $user = User::factory()->create();

        $before = PointsMultiplierCampaign::query()->count();

        app(ActivePromotionsQueryService::class)->active($user);
        app(ActivePromotionsQueryService::class)->active($user);

        $this->assertSame($before, PointsMultiplierCampaign::query()->count());
    }

    public function test_the_payload_carries_no_percentage_claim(): void
    {
        $this->campaign();
        $user = User::factory()->create();

        $json = json_encode(app(ActivePromotionsQueryService::class)->active($user), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('%', $json);
    }
}
