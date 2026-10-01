<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Enums\DrawPublicationStatus;
use App\Models\NationalLotteryDraw;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P1-29: Proves unpublished / hidden draw rows are excluded from the XML sitemap.
 */
final class SitemapPublicationFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_unpublished_or_retracted_draws_never_enter_sitemap(): void
    {
        // 1. Published draw on past date (should enter)
        $published = NationalLotteryDraw::factory()->create([
            'draw_date' => now()->subDays(5)->toDateString(),
            'draw_year' => 2026,
            'publication_status' => DrawPublicationStatus::Published,
        ]);

        // 2. Pending draw (should be excluded)
        $pending = NationalLotteryDraw::factory()->create([
            'draw_date' => now()->subDays(3)->toDateString(),
            'draw_year' => 2026,
            'publication_status' => DrawPublicationStatus::Pending,
        ]);

        // 3. Retracted draw (should be excluded)
        $retracted = NationalLotteryDraw::factory()->create([
            'draw_date' => now()->subDays(2)->toDateString(),
            'draw_year' => 2026,
            'publication_status' => DrawPublicationStatus::Retracted,
        ]);

        // 4. Future draw (should be excluded)
        $future = NationalLotteryDraw::factory()->create([
            'draw_date' => now()->addDays(5)->toDateString(),
            'draw_year' => 2026,
            'publication_status' => DrawPublicationStatus::Published,
        ]);

        $xml = simplexml_load_string((string) $this->get('/sitemap.xml')->getContent());
        $this->assertNotFalse($xml);

        $urls = [];
        foreach ($xml->url as $urlNode) {
            $urls[] = (string) $urlNode->loc;
        }

        $sitemapContent = implode(' ', $urls);

        // Published draw reference is present
        $this->assertStringContainsString((string) $published->draw_reference, $sitemapContent);

        // Unpublished / pending / retracted / future draws are excluded
        $this->assertStringNotContainsString((string) $pending->draw_reference, $sitemapContent);
        $this->assertStringNotContainsString((string) $retracted->draw_reference, $sitemapContent);
        $this->assertStringNotContainsString((string) $future->draw_reference, $sitemapContent);
    }
}
