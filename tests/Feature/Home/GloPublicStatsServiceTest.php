<?php

declare(strict_types=1);

namespace Tests\Feature\Home;

use App\Enums\DrawStatus;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\GloSalesPoint;
use App\Models\User;
use App\Services\Lottery\GloPublicStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The home page shows "Platform statistics". The rule of this codebase is that
 * every figure is a real database COUNT. Competitor-style fixed counters
 * (for example "189,400+ members") must never be hard-coded. These tests pin
 * that rule down.
 */
final class GloPublicStatsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('home.stats');
    }

    /** @return array<string, string> key => value */
    private function metrics(array $payload): array
    {
        $out = [];
        foreach ($payload['metrics'] as $metric) {
            $out[$metric['key']] = $metric['value'];
        }

        return $out;
    }

    public function test_member_metric_equals_the_real_user_count_not_a_fabricated_number(): void
    {
        $payload = app(GloPublicStatsService::class)->publicStats();

        $this->assertSame('AVAILABLE', $payload['status']);
        $this->assertSame((string) User::query()->whereNull('deleted_at')->count(), $this->metrics($payload)['registered_members']);
    }

    public function test_member_count_grows_by_exactly_the_number_of_users_created(): void
    {
        $service = app(GloPublicStatsService::class);
        $before = (int) $this->metrics($service->publicStats())['registered_members'];

        User::factory()->count(3)->create();
        $service->flush();

        $after = (int) $this->metrics($service->publicStats())['registered_members'];
        $this->assertSame($before + 3, $after);
    }

    public function test_soft_deleted_users_are_not_counted_as_members(): void
    {
        $service = app(GloPublicStatsService::class);
        $before = (int) $this->metrics($service->publicStats())['registered_members'];

        User::factory()->count(2)->create();
        User::factory()->create()->delete();
        $service->flush();

        $after = (int) $this->metrics($service->publicStats())['registered_members'];
        $this->assertSame($before + 2, $after, 'the soft-deleted user was counted');
    }

    public function test_published_results_metric_excludes_unpublished_draw_records(): void
    {
        DrawResult::factory()->create([
            'draw_id' => Draw::factory()->create([
                'status' => DrawStatus::ResultPublished,
                'scheduled_at' => now()->subDay(),
            ])->getKey(),
        ]);
        DrawResult::factory()->create([
            'draw_id' => Draw::factory()->create([
                'status' => DrawStatus::Scheduled,
                'scheduled_at' => now()->addDay(),
            ])->getKey(),
        ]);

        $service = app(GloPublicStatsService::class);
        $service->flush();

        $this->assertSame('1', $this->metrics($service->publicStats())['published_results']);
    }

    public function test_future_valid_sales_point_is_not_counted_as_active(): void
    {
        GloSalesPoint::query()->create([
            'sales_point_code' => 'SP-FUTURE-HOME',
            'display_name' => 'Future Home Point',
            'status' => 'active',
            'verification_state' => 'unverified',
            'valid_from' => now()->addDay(),
            'source' => 'operator',
            'source_state' => 'INTERNAL_RECONCILED',
            'metadata' => [],
        ]);

        $service = app(GloPublicStatsService::class);
        $service->flush();

        $this->assertSame('0', $this->metrics($service->publicStats())['sales_points']);
    }

    public function test_every_metric_is_marked_available_and_has_a_label(): void
    {
        $payload = app(GloPublicStatsService::class)->publicStats();

        $this->assertNotEmpty($payload['metrics']);
        foreach ($payload['metrics'] as $metric) {
            $this->assertTrue($metric['available']);
            $this->assertNotSame('', $metric['label']);
            $this->assertMatchesRegularExpression('/^\d+$/', $metric['value']);
        }
    }

    public function test_cached_value_is_served_until_flushed(): void
    {
        $service = app(GloPublicStatsService::class);
        $first = (int) $this->metrics($service->publicStats())['registered_members'];

        User::factory()->count(4)->create();
        $stale = (int) $this->metrics($service->publicStats())['registered_members'];
        $this->assertSame($first, $stale, 'cache should serve the stored value until flushed');

        $service->flush();
        $fresh = (int) $this->metrics($service->publicStats())['registered_members'];
        $this->assertSame($first + 4, $fresh);
    }

    public function test_the_marketing_counters_from_the_competitor_are_not_present(): void
    {
        $payload = app(GloPublicStatsService::class)->publicStats();
        $values = array_values($this->metrics($payload));

        foreach (['18500', '189400', '11400'] as $fixed) {
            $this->assertNotContains($fixed, $values, 'a competitor fixed counter leaked into the stats');
        }
    }
}
