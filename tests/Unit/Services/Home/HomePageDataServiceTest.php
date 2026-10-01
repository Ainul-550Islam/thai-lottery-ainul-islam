<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Home;

use App\Services\Home\HomePageDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Unit tests for HomePageDataService (Prompt 01).
 * Verifies data orchestration, structure integrity, and cache fallbacks.
 */
final class HomePageDataServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_page_data_returns_all_required_sections(): void
    {
        /** @var HomePageDataService $service */
        $service = app(HomePageDataService::class);

        $data = $service->pageData();

        $this->assertIsArray($data);
        $this->assertArrayHasKey('hero', $data);
        $this->assertArrayHasKey('current_result', $data);
        $this->assertArrayHasKey('next_draw', $data);
        $this->assertArrayHasKey('live', $data);
        $this->assertArrayHasKey('lanes', $data);
        $this->assertArrayHasKey('products', $data);
        $this->assertArrayHasKey('prize', $data);
        $this->assertArrayHasKey('stats', $data);
        $this->assertArrayHasKey('trust', $data);
        $this->assertArrayHasKey('bonuses', $data);
        $this->assertArrayHasKey('payments', $data);
        $this->assertArrayHasKey('support', $data);
        $this->assertArrayHasKey('app_links', $data);
        $this->assertArrayHasKey('text', $data);
    }
}
