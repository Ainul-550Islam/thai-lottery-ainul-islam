<?php

declare(strict_types=1);

namespace Tests\Feature\Home;

use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Public pricing must reflect our own canonical matrix, not a static reference sheet. */
final class DiscountParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_discounts_page_is_accessible(): void
    {
        $this->get('/discounts')->assertOk();
    }

    public function test_discounts_page_displays_both_canonical_lottery_families(): void
    {
        $body = (string) $this->get('/discounts')->assertOk()->getContent();
        $this->assertStringContainsString('id="discount-matrix"', $body);
        $this->assertStringContainsString('data-pd-matrix-lottery="national"', $body);
        $this->assertStringContainsString('data-pd-matrix-lottery="bangkok_weekly"', $body);
        $this->assertStringNotContainsString('THAILOTTO.CLUB', strtoupper($body));
    }

    public function test_discounts_page_projects_configured_rates_and_both_modes(): void
    {
        // Deliberately differ from the default headline rates: static copied
        // numbers must not satisfy this check when the operator changes config.
        config([
            'lotto_discount_matrix.lotteries.national.games.national_six_digit.discount' => '41.00',
            'lotto_discount_matrix.lotteries.national.games.national_six_digit.modes.D' => '2345',
            'lotto_discount_matrix.lotteries.national.games.national_six_digit.modes.R' => '321',
        ]);

        $body = (string) $this->get('/discounts')->assertOk()->getContent();
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$body);
        $rows = (new DOMXPath($dom))->query('//tr[@data-pd-matrix-game="national_six_digit"]');
        $this->assertNotFalse($rows);
        $this->assertSame(1, $rows->length);
        $row = $rows->item(0)->textContent;
        $this->assertStringContainsString('41.00%', $row);
        $this->assertStringContainsString('2345', $row);
        $this->assertStringContainsString('321', $row);
        $this->assertStringContainsString('THB', $row);
        $this->assertStringNotContainsString('$', $body);
    }

    public function test_discounts_page_contains_bangkok_weekly(): void
    {
        $body = (string) $this->get('/discounts')->assertOk()->getContent();
        $this->assertStringContainsString(trans('prize_discount.matrix_lottery_bangkok_weekly'), $body);
        $this->assertStringContainsString('data-pd-matrix-game="weekly_6_ball"', $body);
    }

    public function test_discounts_page_uses_a_server_projected_matrix(): void
    {
        $body = (string) $this->get('/discounts')->assertOk()->getContent();
        $this->assertStringContainsString('data-rates-source="server-projection"', $body);
        $this->assertStringContainsString('NOT_CONFIGURED', $body);
    }
}
