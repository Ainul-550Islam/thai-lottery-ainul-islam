<?php

declare(strict_types=1);

namespace Tests\Unit\Services\PublicPages;

use App\Services\PublicPages\VisionMissionService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class VisionMissionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_service_returns_the_complete_public_content_contract(): void
    {
        $data = app(VisionMissionService::class)->data('en');

        foreach (['vision', 'mission', 'clear', 'core_values', 'governance', 'journey', 'cta'] as $key) {
            $this->assertArrayHasKey($key, $data);
        }
        $this->assertCount(5, $data['clear']['items']);
        $this->assertNotEmpty($data['governance']['controls']);
        $this->assertNotEmpty($data['cta']['links']);
    }

    public function test_service_uses_localized_content_without_private_fields(): void
    {
        $data = app(VisionMissionService::class)->data('th');

        $this->assertSame('th', $data['locale']);
        $this->assertNotSame('', $data['hero']['title']);
        $this->assertNotSame('', $data['mission']['text']);
        $this->assertArrayNotHasKey('admin_notes', $data);
        $this->assertArrayNotHasKey('secret', $data);
    }
}
