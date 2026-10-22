<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Home;

use App\Enums\GloSourceState;
use App\Services\Home\HomeResultFeedService;
use App\Services\Home\PublicLaneResultDigestService;
use App\Services\Lottery\GloPublicHomeService;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

final class HomeResultFeedServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_aggregate_does_not_mark_fixture_result_as_verified(): void
    {
        $gloHome = Mockery::mock(GloPublicHomeService::class);
        $gloHome->shouldReceive('currentResultCard')->once()->andReturn([
            'status' => 'AVAILABLE',
            'has_result' => true,
            'source_state' => GloSourceState::FixtureOnly->value,
        ]);

        $laneResults = Mockery::mock(PublicLaneResultDigestService::class);
        $laneResults->shouldReceive('lanes')->once()->andReturn([
            'status' => 'OK',
            'lanes' => [],
        ]);

        $feed = (new HomeResultFeedService($gloHome, $laneResults))->getLatestResultsFeed();

        self::assertSame('AVAILABLE', $feed['status']);
        self::assertTrue($feed['has_results']);
        self::assertSame(GloSourceState::FixtureOnly->value, $feed['primary_glo']['source_state']);
        self::assertArrayHasKey('generated_at', $feed);
        self::assertArrayNotHasKey('verified_at', $feed);
    }

    public function test_empty_feed_is_not_claimed_as_verified_or_available(): void
    {
        $gloHome = Mockery::mock(GloPublicHomeService::class);
        $gloHome->shouldReceive('currentResultCard')->once()->andReturn([
            'status' => 'NO_VERIFIED_RESULT',
            'has_result' => false,
        ]);

        $laneResults = Mockery::mock(PublicLaneResultDigestService::class);
        $laneResults->shouldReceive('lanes')->once()->andReturn([
            'status' => 'OK',
            'lanes' => [['available' => false]],
        ]);

        $feed = (new HomeResultFeedService($gloHome, $laneResults))->getLatestResultsFeed();

        self::assertSame('NO_PUBLISHED_RESULTS', $feed['status']);
        self::assertFalse($feed['has_results']);
        self::assertArrayNotHasKey('verified_at', $feed);
    }
}
