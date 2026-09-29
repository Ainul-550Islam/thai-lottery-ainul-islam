<?php

namespace Tests\Feature\SEO;

use App\Models\BingoLotteryDraw;
use App\Models\NationalLotteryDraw;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * XML SITEMAP (FINAL AUDIT #15).
 *
 * The sitemap contains canonical public URLs only: real lottery year and
 * detail pages for every lane, the static public pages — and never
 * authenticated/admin/API/search endpoints or legacy `.php` duplicates.
 */
class SitemapTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sitemap_is_valid_xml_with_a_urlset(): void
    {
        $response = $this->get('/sitemap.xml');

        $response->assertOk();
        $this->assertSame('application/xml; charset=UTF-8', $response->headers->get('Content-Type'));

        $xml = simplexml_load_string((string) $response->getContent());
        $this->assertNotFalse($xml);
        $this->assertSame('urlset', $xml->getName());

        $namespaces = $xml->getDocNamespaces(false);
        $this->assertSame(
            'http://www.sitemaps.org/schemas/sitemap/0.9',
            $namespaces[''] ?? null,
        );
    }

    public function test_static_canonical_pages_are_included(): void
    {
        $xml = $this->sitemapUrls();

        foreach (['/results', '/check', '/about', '/terms', '/privacy', '/fees', '/discounts', '/contact', '/sales-points', '/account-grades', '/account-verification-guide', '/prize-verification', '/vision'] as $path) {
            $this->assertContains($this->url($path), $xml, $path.' must be in the sitemap.');
        }
    }

    public function test_real_lottery_year_and_detail_pages_are_included(): void
    {
        NationalLotteryDraw::factory()->count(2)->create(['draw_year' => 2568]);
        BingoLotteryDraw::factory()->create(['draw_year' => 2567]);

        $xml = $this->sitemapUrls();

        $this->assertContains($this->url('/national-lottery'), $xml);
        $this->assertContains($this->url('/national-lottery/year/2568'), $xml);
        $this->assertContains($this->url('/bingo-lottery'), $xml);
        $this->assertContains($this->url('/bingo-lottery/year/2567'), $xml);

        // Every draw detail page that exists is listed exactly once.
        foreach (NationalLotteryDraw::all() as $draw) {
            $this->assertSame(1, substr_count(implode(' ', $xml), (string) $draw->draw_reference));
        }
    }

    public function test_years_without_draws_are_not_included(): void
    {
        $xml = $this->sitemapUrls();

        $this->assertNotContains($this->url('/national-lottery/year/2540'), $xml);
        $this->assertNotContains($this->url('/weekly-lottery/year/2500'), $xml);
    }

    public function test_authenticated_admin_api_and_payment_pages_are_excluded(): void
    {
        $xml = $this->sitemapUrls();

        $banned = ['/login', '/register', '/dashboard', '/wallet', '/deposit', '/withdraw', '/bets', '/profile', '/admin', '/api', '/payment/success', '/payment/failure', '/payment/cancel', '/payment/pending'];

        foreach ($banned as $path) {
            $this->assertNotContains($this->url($path), $xml, $path.' must never be in the sitemap.');
        }
    }

    public function test_no_legacy_php_duplicates_are_canonised(): void
    {
        $xml = $this->sitemapUrls();

        foreach ($xml as $url) {
            $this->assertStringNotContainsString('.php', $url, 'Legacy .php URLs must not appear in the sitemap.');
        }
    }

    public function test_search_form_endpoints_are_excluded(): void
    {
        $xml = $this->sitemapUrls();

        $this->assertNotContains($this->url('/national-lottery/search'), $xml);
        $this->assertNotContains($this->url('/weekly-lottery/search'), $xml);
        $this->assertNotContains($this->url('/bingo-lottery/search'), $xml);
        $this->assertNotContains($this->url('/pcso-lottery/search'), $xml);
    }

    public function test_robots_txt_declares_the_sitemap(): void
    {
        $robots = (string) file_get_contents(dirname(__DIR__, 3).'/public/robots.txt');

        $this->assertStringContainsString('Sitemap:', $robots);
    }

    /**
     * @return array<int, string>
     */
    private function sitemapUrls(): array
    {
        $xml = simplexml_load_string((string) $this->get('/sitemap.xml')->getContent());
        $this->assertNotFalse($xml);

        $urls = [];
        foreach ($xml->url as $urlNode) {
            $urls[] = (string) $urlNode->loc;
        }

        return $urls;
    }

    private function url(string $path): string
    {
        return rtrim(config('app.url'), '/').$path;
    }
}
