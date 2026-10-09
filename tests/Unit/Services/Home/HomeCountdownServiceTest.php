<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Home;

use App\Services\Home\HomeCountdownService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Read-only contract tests for HomeCountdownService.
 *
 * These cases create no rows and assert only the normalized projection, so they
 * intentionally avoid RefreshDatabase's migrate:fresh cycle against the shared
 * SQLite test database. The service itself remains exercised through the real
 * database connection; no test is skipped or mocked out.
 */
final class HomeCountdownServiceTest extends TestCase
{
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
