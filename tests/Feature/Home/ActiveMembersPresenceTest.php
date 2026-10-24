<?php

declare(strict_types=1);

namespace Tests\Feature\Home;

use App\Models\User;
use App\Services\Lottery\GloPublicStatsService;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Competitor parity C17: a "members active" counter that is session-based
 * (logged-in members active in the window), read from real data.
 */
final class ActiveMembersPresenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        // No compiled front-end build exists in the test sandbox. These tests
        // assert on presence tracking, not on asset output.
        $this->withoutVite();
    }

    private function lastSeen(User $user): ?string
    {
        return DB::table('users')->where('id', $user->id)->value('last_seen_at');
    }

    public function test_a_logged_in_request_records_last_seen_at(): void
    {
        $member = User::factory()->create(['last_seen_at' => null]);

        $this->actingAs($member)->get(route('about'))->assertOk();

        $this->assertNotNull($this->lastSeen($member));
    }

    public function test_a_guest_request_records_nothing(): void
    {
        $this->get(route('about'))->assertOk();

        $this->assertSame(0, DB::table('users')->whereNotNull('last_seen_at')->count());
    }

    public function test_presence_is_written_at_most_once_per_minute_per_member(): void
    {
        $member = User::factory()->create(['last_seen_at' => null]);

        $this->actingAs($member)->get(route('about'))->assertOk();
        $first = $this->lastSeen($member);

        // Rewind the stored value. A second request within the same minute must
        // not write again, because the cache lock is still held.
        DB::table('users')->where('id', $member->id)->update(['last_seen_at' => now()->subMinutes(3)]);
        $this->actingAs($member)->get(route('about'))->assertOk();

        $this->assertNotNull($first);
        $this->assertTrue(
            Carbon::parse($this->lastSeen($member))->lt(now()->subMinutes(2)),
            'A second request in the same minute must not rewrite last_seen_at.'
        );
    }

    public function test_the_active_count_only_includes_members_seen_inside_the_window(): void
    {
        $service = app(GloPublicStatsService::class);

        User::factory()->create(['last_seen_at' => now()->subMinutes(2)]);
        User::factory()->create(['last_seen_at' => now()->subMinutes(GloPublicStatsService::ACTIVE_WINDOW_MINUTES - 1)]);
        User::factory()->create(['last_seen_at' => now()->subMinutes(GloPublicStatsService::ACTIVE_WINDOW_MINUTES + 5)]);
        User::factory()->create(['last_seen_at' => null]);

        $this->assertSame(2, $service->activeMembersNow());
    }

    public function test_soft_deleted_members_are_not_counted_as_active(): void
    {
        $service = app(GloPublicStatsService::class);

        $gone = User::factory()->create(['last_seen_at' => now()]);
        $gone->delete();

        $this->assertSame(0, $service->activeMembersNow());
    }

    public function test_the_public_stats_payload_exposes_the_live_active_metric(): void
    {
        User::factory()->create(['last_seen_at' => now()]);
        User::factory()->create(['last_seen_at' => now()->subHour()]);

        $stats = app(GloPublicStatsService::class)->publicStats();

        $this->assertSame('VERIFIED', $stats['status']);

        $metric = collect($stats['metrics'])->firstWhere('key', 'active_members');
        $this->assertNotNull($metric);
        $this->assertTrue($metric['available']);
        $this->assertSame('1', $metric['value']);
        $this->assertStringContainsString((string) GloPublicStatsService::ACTIVE_WINDOW_MINUTES, $metric['label']);
    }

    public function test_the_live_metric_is_not_frozen_by_the_stats_cache(): void
    {
        $service = app(GloPublicStatsService::class);

        $this->assertSame('0', collect($service->publicStats()['metrics'])->firstWhere('key', 'active_members')['value']);

        // A member becomes active after the payload was cached.
        User::factory()->create(['last_seen_at' => now()]);

        $this->assertSame('1', collect($service->publicStats()['metrics'])->firstWhere('key', 'active_members')['value']);
    }
}
