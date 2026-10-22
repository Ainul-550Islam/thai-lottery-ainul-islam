<?php

declare(strict_types=1);

namespace Tests\Feature\Pages35To44;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Route and fail-closed coverage for the independently addressable Pages
 * 35–44 surface. Database-backed result assertions remain in the lane tests;
 * this suite protects page identity, route ordering and purchase safety.
 */
final class IndependentPublicPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_pcso_page_routes_are_named_before_the_compatibility_wildcard(): void
    {
        $routes = app('router')->getRoutes();

        $this->assertSame('pcso-lottery.search', $routes->match(Request::create('/pcso-lottery/search', 'GET'))->getName());
        $this->assertSame('pcso-lottery.year', $routes->match(Request::create('/pcso-lottery/year/2026', 'GET'))->getName());
        $this->assertSame('pcso-lottery.archive', $routes->match(Request::create('/pcso-lottery/archive/2026', 'GET'))->getName());
        $this->assertSame('pcso-lottery.draw', $routes->match(Request::create('/pcso-lottery/draw/PCSO-20260930-1400', 'GET'))->getName());
        $this->assertSame('pcso-lottery.result', $routes->match(Request::create('/pcso-lottery/result/PCSO-20260930-1400', 'GET'))->getName());
    }

    public function test_pcso_purchase_page_is_fail_closed(): void
    {
        $this->get(route('pcso-lottery.buy'))
            ->assertOk()
            ->assertSee('NOT_CONFIGURED', false)
            ->assertSee('disabled', false)
            ->assertDontSee('Add to PCSO Bet Slip', false);
    }

    public function test_dedicated_glo_l6_home_is_not_the_legacy_results_route(): void
    {
        $this->get(route('glo-l6.index'))
            ->assertOk()
            ->assertSee('GLO L6', false)
            ->assertSee('Purchase unavailable', false)
            ->assertDontSee('482963', false);
    }

    public function test_glo_l6_home_has_no_new_home_api_route(): void
    {
        $routes = app('router')->getRoutes();

        $this->assertSame('glo-l6.index', $routes->match(Request::create('/glo-l6', 'GET'))->getName());
        $this->assertNull($routes->getByName('api.v1.glo-l6.home'));
    }
}
