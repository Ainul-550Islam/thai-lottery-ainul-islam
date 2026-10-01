<?php

declare(strict_types=1);

namespace Tests\Feature\Lottery;

use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Route and source-surface checks for Pages 25–34.
 *
 * These checks deliberately do not seed a result or invent a product purchase
 * contract. Result values remain the responsibility of the existing Weekly and
 * Bingo/Mega services and their public projections.
 */
final class Pages25To34Test extends TestCase
{
    /**
     * @dataProvider pageRouteProvider
     */
    public function test_each_page_has_an_independent_public_route(string $path, string $expectedName): void
    {
        $route = app('router')->getRoutes()->match(Request::create($path, 'GET'));

        $this->assertSame($expectedName, $route->getName());
        $this->assertContains('public.legal', $route->gatherMiddleware());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function pageRouteProvider(): array
    {
        return [
            '25 weekly draw detail' => ['/weekly-lottery/draw/EXAMPLE-DRAW', 'weekly-lottery.draw'],
            '26 weekly latest result' => ['/weekly-lottery/latest', 'weekly-lottery.latest'],
            '27 weekly historical results' => ['/weekly-lottery/history', 'weekly-lottery.history'],
            '28 weekly year archive' => ['/weekly-lottery/archive/2026', 'weekly-lottery.archive'],
            '29 weekly result detail' => ['/weekly-lottery/result/EXAMPLE-DRAW', 'weekly-lottery.result'],
            '30 mega lottery' => ['/bingo-lottery', 'bingo-lottery.index'],
            '31 mega purchase page' => ['/bingo-lottery/buy', 'bingo-lottery.buy'],
            '32 mega draw detail' => ['/bingo-lottery/draw/EXAMPLE-DRAW', 'bingo-lottery.draw'],
            '33 mega latest result' => ['/bingo-lottery/latest', 'bingo-lottery.latest'],
            '34 mega historical results' => ['/bingo-lottery/history', 'bingo-lottery.history'],
        ];
    }

    public function test_mega_pages_remain_in_the_approved_bingo_route_family(): void
    {
        $routes = app('router')->getRoutes();
        $paths = [];

        foreach ($routes as $route) {
            $uri = '/'.ltrim($route->uri(), '/');

            if (str_starts_with(ltrim($uri, '/'), 'bingo-lottery')) {
                $paths[] = ltrim($uri, '/');
            }
        }

        $this->assertContains('bingo-lottery', $paths);
        $this->assertNotContains('mega-lottery', $paths);
    }

    public function test_legacy_mega_path_still_resolves_to_the_canonical_index(): void
    {
        $response = $this->get('/bingo-lottery.php');

        $response->assertRedirect(route('bingo-lottery.index'));
        $this->assertSame(301, $response->getStatusCode());
    }

    public function test_mega_landing_surface_contains_no_static_speed_round_claims(): void
    {
        $content = (string) file_get_contents(resource_path('views/bingo-lottery/index.blade.php'));

        foreach (['Round #68', 'BINGO-2026-R67', '50,000', '04', '28', '17:00:00', '฿50,000', '฿900', '฿95'] as $unsupportedValue) {
            $this->assertStringNotContainsString($unsupportedValue, $content);
        }

        $this->assertStringContainsString("x-bingo-lottery.result-card", $content);
        $this->assertStringContainsString("route('bingo-lottery.buy')", $content);
    }

    public function test_mega_purchase_surface_is_fail_closed(): void
    {
        $content = (string) file_get_contents(resource_path('views/bingo-lottery/buy.blade.php'));

        $this->assertStringContainsString("purchase_not_configured", $content);
        $this->assertStringContainsString("NOT_CONFIGURED", (string) file_get_contents(lang_path('en/bingo_lottery.php')));
        $this->assertStringNotContainsString('price', strtolower($content));
        $this->assertStringNotContainsString('wallet', strtolower($content));
        $this->assertStringNotContainsString('purchase success', strtolower($content));
    }

    public function test_mega_translation_files_have_matching_top_level_keys(): void
    {
        $english = require lang_path('en/bingo_lottery.php');
        $thai = require lang_path('th/bingo_lottery.php');

        $this->assertSame(array_keys($english), array_keys($thai));
        $this->assertSame(array_keys($english['search_type']), array_keys($thai['search_type']));
        $this->assertSame(array_keys($english['provenance']), array_keys($thai['provenance']));
        $this->assertSame(array_keys($english['integrity']), array_keys($thai['integrity']));
        $this->assertSame(array_keys($english['integrity_status']), array_keys($thai['integrity_status']));
        $this->assertSame(array_keys($english['integrity_hint']), array_keys($thai['integrity_hint']));
        $this->assertSame(array_keys($english['source_state']), array_keys($thai['source_state']));
        $this->assertSame(array_keys($english['source_state_hint']), array_keys($thai['source_state_hint']));
        $this->assertSame(array_keys($english['status']), array_keys($thai['status']));
    }
}
