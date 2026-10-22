<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Home;

use App\Services\Home\HomePageDataService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Unit tests for HomePageDataService (Prompt 01).
 * Verifies data orchestration, structure integrity, and cache fallbacks.
 */
final class HomePageDataServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_trust_bullets_filter_empty_and_non_string_config_values(): void
    {
        config(['home.trust.bullets' => [' ', 'Configured claim', null, ['nested']]]);

        $data = app(HomePageDataService::class)->pageData();

        $this->assertSame('CONFIGURED', $data['trust']['status']);
        $this->assertSame(['Configured claim'], $data['trust']['bullets']);
    }

    public function test_empty_or_invalid_trust_configuration_stays_not_configured(): void
    {
        config(['home.trust.bullets' => [' ', null, ['nested']]]);

        $data = app(HomePageDataService::class)->pageData();

        $this->assertSame('NOT_CONFIGURED', $data['trust']['status']);
        $this->assertSame([], $data['trust']['bullets']);
    }

    public function test_page_data_returns_all_required_sections(): void
    {
        /** @var HomePageDataService $service */
        $service = app(HomePageDataService::class);

        $data = $service->pageData();

        $this->assertIsArray($data);
        $this->assertArrayHasKey('hero', $data);
        $this->assertSame('AVAILABLE', $data['hero']['status']);
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
