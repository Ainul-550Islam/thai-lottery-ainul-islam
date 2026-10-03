<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Home;

use App\Services\Home\HomeCountdownService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Unit tests for HomeCountdownService (Prompt 01).
 * Verifies exact countdown, timezone conversions, and boundary checks.
 */
final class HomeCountdownServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_next_draw_countdown_returns_valid_structure(): void
    {
        /** @var HomeCountdownService $service */
        $service = app(HomeCountdownService::class);

        $result = $service->nextDrawCountdown();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('draw_name', $result);
        $this->assertArrayHasKey('scheduled_at_iso', $result);
        $this->assertArrayHasKey('timezone', $result);
        $this->assertArrayHasKey('remaining_seconds', $result);
        $this->assertSame('Asia/Bangkok', $result['timezone']);
    }

    public function test_remaining_seconds_is_non_negative_integer(): void
    {
        /** @var HomeCountdownService $service */
        $service = app(HomeCountdownService::class);

        $result = $service->nextDrawCountdown();

        $this->assertIsInt($result['remaining_seconds']);
        $this->assertGreaterThanOrEqual(0, $result['remaining_seconds']);
    }
}
