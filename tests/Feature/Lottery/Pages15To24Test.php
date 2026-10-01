<?php

declare(strict_types=1);

namespace Tests\Feature\Lottery;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Addressability and fail-closed checks for Pages 15–24.
 *
 * The result lanes are intentionally queried through their existing services;
 * this test does not seed numbers or invent a purchase fixture. In an empty
 * database the public pages must still render honest unavailable states.
 */
final class Pages15To24Test extends TestCase
{
    use RefreshDatabase;

    public function test_page_routes_are_named_and_addressable(): void
    {
        foreach ([
            'lotteries.index',
            'national-lottery.index',
            'national-lottery.buy',
            'national-lottery.latest',
            'national-lottery.history',
            'national-lottery.year',
            'national-lottery.year-archive',
            'national-lottery.draw-detail',
            'national-lottery.result-detail',
            'national-lottery.show',
            'weekly-lottery.index',
            'weekly-lottery.buy',
            'weekly-lottery.search',
            'weekly-lottery.year',
            'weekly-lottery.show',
        ] as $routeName) {
            $this->assertNotNull(app('router')->getRoutes()->getByName($routeName), $routeName);
        }
    }

    public function test_hub_and_purchase_pages_do_not_fabricate_product_values(): void
    {
        $this->get(route('lotteries.index'))
            ->assertOk()
            ->assertDontSee('482963', false)
            ->assertDontSee('7419', false)
            ->assertDontSee('฿6,000,000', false);

        $this->get(route('national-lottery.buy'))
            ->assertOk()
            ->assertSee('NOT_CONFIGURED', false)
            ->assertDontSee('purchase_success', false);

        $this->get(route('weekly-lottery.buy'))
            ->assertOk()
            ->assertSee('NOT_CONFIGURED', false)
            ->assertDontSee('purchase_success', false);
    }

    public function test_independent_national_surfaces_use_empty_states_without_database_values(): void
    {
        $this->get(route('national-lottery.latest'))->assertOk()->assertSee(trans('national_lottery.empty_current'), false);
        $this->get(route('national-lottery.history'))->assertOk()->assertSee(trans('national_lottery.empty_year'), false);
        $this->get(route('national-lottery.year', ['year' => 2024]))->assertOk();
        $this->get(route('national-lottery.year-archive', ['year' => 2024]))->assertOk();
        $this->get(route('national-lottery.draw-detail', ['draw' => 'NL-20240101']))->assertOk()->assertSee(trans('national_lottery.status.result_not_found'), false);
        $this->get(route('national-lottery.result-detail', ['draw' => 'NL-20240101']))->assertOk()->assertSee(trans('national_lottery.status.result_not_found'), false);
    }
}
