<?php

declare(strict_types=1);

namespace Tests\Unit\Services\PublicPages;

use App\Services\PublicPages\AboutTimelineService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class AboutTimelineServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_timeline_records_are_valid_and_in_display_order(): void
    {
        $items = app(AboutTimelineService::class)->milestones('en');

        $this->assertNotEmpty($items);
        $orders = array_column($items, 'display_order');
        $this->assertSame($orders, array_values(array_unique($orders)));
        $this->assertSame($orders, array_values($orders));

        foreach ($items as $item) {
            $this->assertMatchesRegularExpression('/^[0-9]{4}$/', $item['year']);
            $this->assertNotSame('', trim($item['title']));
            $this->assertNotSame('', trim($item['description']));
            $this->assertGreaterThan(0, $item['display_order']);
        }
    }

    public function test_timeline_supports_both_locales(): void
    {
        $english = app(AboutTimelineService::class)->milestones('en');
        $thai = app(AboutTimelineService::class)->milestones('th');

        $this->assertCount(count($english), $thai);
        $this->assertNotSame($english[0]['description'], $thai[0]['description']);
    }
}
