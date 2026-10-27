<?php

declare(strict_types=1);

namespace Tests\Feature\Glo;

use App\Enums\UserStatus;
use App\Models\GloSalesPoint;
use App\Models\User;
use App\Services\Lottery\GloDealerService;
use App\Services\Lottery\GloSalesPointService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * GLO-16 sales points: own daily location, public search, bounded geo, privacy.
 */
class GloSalesPointTest extends TestCase
{
    use DatabaseTruncation;

    private User $dealerUser;

    private User $otherDealer;

    private string $token;

    private string $otherToken;

    private \App\Models\GloDealer $dealer;

    private GloSalesPointService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->service = app(GloSalesPointService::class);
        $dealers = app(GloDealerService::class);

        $this->dealerUser = $this->freshUser()->create();
        $this->dealer = $dealers->ensureDealerForUser($this->dealerUser);
        $dealers->activate($this->dealer);
        $this->dealer->refresh();
        $this->token = $this->dealerUser->createToken('api')->plainTextToken;

        $this->otherDealer = $this->freshUser()->create();
        $dealers->ensureDealerForUser($this->otherDealer);
        $this->otherToken = $this->otherDealer->createToken('api')->plainTextToken;
    }

    public function test_update_own_daily_location_and_history(): void
    {
        $history = $this->service->updateDailyLocation($this->dealer, [
            'display_name' => 'Corner Shop',
            'address' => '1 Main St',
            'province' => 'Bangkok',
            'district' => 'Khlong Toei',
            'latitude' => 13.7563,
            'longitude' => 100.5018,
        ], $this->dealerUser);

        $eff = $history->effective_date;
        $effStr = $eff instanceof \Illuminate\Support\Carbon ? $eff->toDateString() : (string) $eff;
        $this->assertSame(now()->toDateString(), $effStr);
        $this->assertDatabaseCount('glo_sales_point_history', 1);

        $point = GloSalesPoint::query()->where('dealer_id', $this->dealer->getKey())->first();
        $this->assertNotNull($point);
        $this->assertEqualsWithDelta(13.7563, (float) $point->latitude, 0.0000001);
    }

    public function test_duplicate_daily_update_rejected(): void
    {
        $input = ['display_name' => 'A', 'latitude' => 13.7, 'longitude' => 100.5];
        $this->service->updateDailyLocation($this->dealer, $input, $this->dealerUser);

        try {
            $this->service->updateDailyLocation($this->dealer, $input, $this->dealerUser);
            $this->fail('duplicate same-day update must 409');
        } catch (\App\Exceptions\GloDealerException $e) {
            $this->assertStringContainsString('already recorded', $e->getMessage());
        }

        $this->assertDatabaseCount('glo_sales_point_history', 1);
    }

    public function test_cannot_update_another_dealers_location(): void
    {
        $stranger = $this->freshUser()->create();

        try {
            $this->service->updateDailyLocation($this->dealer, [
                'display_name' => 'Hijack',
            ], $stranger);
            $this->fail('cross-dealer update must 403');
        } catch (\App\Exceptions\GloDealerException $e) {
            $this->assertStringContainsString('Not authorized', $e->getMessage());
        }
    }

    public function test_cannot_backdate_location(): void
    {
        try {
            $this->service->updateDailyLocation($this->dealer, [
                'display_name' => 'Old',
                'effective_date' => now()->subDays(3)->toDateString(),
            ], $this->dealerUser);
            $this->fail('backdated location must 422');
        } catch (\App\Exceptions\GloDealerException $e) {
            $this->assertStringContainsString('today', $e->getMessage());
        }
    }

    public function test_invalid_coordinates_rejected(): void
    {
        try {
            $this->service->updateDailyLocation($this->dealer, [
                'display_name' => 'Bad',
                'latitude' => 999.0,
                'longitude' => 100.0,
            ], $this->dealerUser);
            $this->fail('latitude 999 must 422');
        } catch (\App\Exceptions\GloDealerException $e) {
            $this->assertStringContainsString('latitude', $e->getMessage());
        }
    }

    public function test_paired_coordinates_required_when_partial(): void
    {
        try {
            $this->service->updateDailyLocation($this->dealer, [
                'display_name' => 'Partial',
                'latitude' => 13.7,
            ], $this->dealerUser);
            $this->fail('lat without lng must 422');
        } catch (\App\Exceptions\GloDealerException $e) {
            $this->assertStringContainsString('both', $e->getMessage());
        }
    }

    public function test_inactive_dealer_cannot_update_location(): void
    {
        $pending = app(GloDealerService::class)->ensureDealerForUser($this->freshUser()->create());

        try {
            $this->service->updateDailyLocation($pending, ['display_name' => 'X'], $pending->user()->first());
            $this->fail('pending dealer must not update location');
        } catch (\App\Exceptions\GloDealerException $e) {
            $this->assertStringContainsString('not active', $e->getMessage());
        }
    }

    public function test_api_update_sales_location(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/v1/glo/dealer/sales-location', [
                'display_name' => 'API Stall',
                'province' => 'Chiang Mai',
                'latitude' => 18.7883,
                'longitude' => 98.9853,
            ])
            ->assertOk()
            ->assertJsonPath('data.recorded', true);
    }

    public function test_public_search_lists_points_without_pii(): void
    {
        $this->service->updateDailyLocation($this->dealer, [
            'display_name' => 'Public Stall',
            'address' => '10 Sukhumvit',
            'province' => 'Bangkok',
            'latitude' => 13.74,
            'longitude' => 100.53,
            'public_contact' => null,
        ], $this->dealerUser);

        $response = $this->getJson('/api/v1/glo/sales-points');
        $response->assertOk();

        $rows = $response->json('data');
        $this->assertNotEmpty($rows);
        $first = $rows[0];

        $this->assertArrayHasKey('name', $first);
        $this->assertArrayHasKey('province', $first);
        $this->assertArrayNotHasKey('dealer_id', $first);
        $this->assertArrayNotHasKey('audit_fingerprint', $first);
        $this->assertArrayNotHasKey('metadata', $first);
        // No credentials keys.
        $this->assertArrayNotHasKey('password', $first);
        $this->assertArrayNotHasKey('email', $first);
    }

    public function test_public_search_radius_filters_and_bounds(): void
    {
        // Point A near Bangkok centre.
        $this->service->updateDailyLocation($this->dealer, [
            'display_name' => 'Near',
            'latitude' => 13.7563,
            'longitude' => 100.5018,
        ], $this->dealerUser);

        // Point B far away (different dealer).
        $farDealer = app(GloDealerService::class)->ensureDealerForUser($this->freshUser()->create());
        app(GloDealerService::class)->activate($farDealer);
        $farDealer->refresh();
        $this->service->updateDailyLocation($farDealer, [
            'display_name' => 'Far',
            'latitude' => 18.7883, // Chiang Mai
            'longitude' => 99.9853,
        ], $farDealer->user()->first());

        $near = $this->getJson('/api/v1/glo/sales-points?latitude=13.7563&longitude=100.5018&radius=5');
        $near->assertOk();
        $names = array_column($near->json('data'), 'name');
        $this->assertContains('Near', $names);
        $this->assertNotContains('Far', $names);

        // Radius above max rejected.
        $this->getJson('/api/v1/glo/sales-points?latitude=13.7563&longitude=100.5018&radius=500')
            ->assertStatus(422);
    }

    public function test_public_search_pagination_bounded(): void
    {
        $this->service->updateDailyLocation($this->dealer, [
            'display_name' => 'PageOne',
            'province' => 'Bangkok',
            'latitude' => 13.7,
            'longitude' => 100.5,
        ], $this->dealerUser);

        $response = $this->getJson('/api/v1/glo/sales-points?page=1');
        $response->assertOk();
        $this->assertArrayHasKey('meta', $response->json());
        $total = $response->json('meta.total');
        $this->assertLessThanOrEqual((int) config('glo.sales_points.max_results'), $total);
    }

    public function test_search_without_coordinates_returns_normal_results(): void
    {
        $this->service->updateDailyLocation($this->dealer, [
            'display_name' => 'NoGeo',
            'province' => 'Phuket',
        ], $this->dealerUser);

        $this->getJson('/api/v1/glo/sales-points?province=Phuket')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'NoGeo');
    }

    public function test_official_sync_reports_not_configured(): void
    {
        $status = $this->service->officialSyncStatus();
        $this->assertSame('not_configured', $status['status']);
        $this->assertSame('NOT_CONFIGURED', $status['source_state']);
    }

    public function test_sync_command_not_configured(): void
    {
        $this->artisan('glo:sync-sales-points', ['--json' => true])
            ->assertExitCode(0);
    }

    public function test_partial_radius_query_rejected(): void
    {
        $this->getJson('/api/v1/glo/sales-points?latitude=13.7')
            ->assertStatus(422);
    }

    private function freshUser(): \Illuminate\Database\Eloquent\Factories\Factory
    {
        return User::factory()->state(fn (): array => [
            'email' => \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(12)).'.'.bin2hex(random_bytes(4)).'@glofreeze.local',
            'username' => 'gz'.\Illuminate\Support\Str::random(10),
            'phone' => '+8801'.random_int(100_000_000, 999_999_999),
            'status' => UserStatus::Active,
        ]);
    }
}
