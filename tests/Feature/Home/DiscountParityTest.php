<?php

declare(strict_types=1);

namespace Tests\Feature\Home;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FIX B — Discount Parity (P1).
 *
 * thailotto.club/discount.php live rates:
 *   National Lottery Affiliate Commission 2%
 *   Six Digit D-$1 × 3000 & R-$1 × 500 Discount 35%
 *   3Up D-$1 × 500 & R-$1 × 100 Discount 30%
 *
 * Our discounts/index.blade.php must render the TH parity section.
 */
final class DiscountParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_discounts_page_is_accessible(): void
    {
        $response = $this->get('/discounts');
        $response->assertOk();
    }

    public function test_discounts_page_contains_thailotto_parity_section(): void
    {
        $content = (string) $this->get('/discounts')->assertOk()->getContent();

        // TH parity section must exist
        $this->assertStringContainsString('id="thai-lottery-discounts"', $content, 'Missing TH parity section #thai-lottery-discounts');
        $this->assertStringContainsString('THAILOTTO.CLUB PARITY', $content);
        $this->assertStringContainsString('National Lottery', $content);
    }

    public function test_discounts_page_shows_correct_rates(): void
    {
        $content = (string) $this->get('/discounts')->assertOk()->getContent();

        // Six Digit Direct/Reverse 35%
        $this->assertStringContainsString('35%', $content, 'Missing Six Digit 35% discount');
        $this->assertStringContainsString('D-$1', $content, 'Missing D-$1 notation');
        $this->assertStringContainsString('R-$1', $content, 'Missing R-$1 notation');
        $this->assertStringContainsString('3000', $content, 'Missing ×3000 payout');

        // 3Up Direct/Reverse 30%
        $this->assertStringContainsString('30%', $content, 'Missing 3Up 30% discount');
        $this->assertStringContainsString('3 Up', $content, 'Missing 3 Up label');
        $this->assertStringContainsString('500', $content, 'Missing ×500 payout');

        // Affiliate Commission 2%
        $this->assertStringContainsString('2%', $content, 'Missing Affiliate 2%');
        $this->assertStringContainsString('Affiliate', $content);
        $this->assertStringContainsString('Commission', $content);
    }

    public function test_discounts_page_contains_bangkok_weekly(): void
    {
        $content = (string) $this->get('/discounts')->assertOk()->getContent();

        $this->assertStringContainsString('Bangkok Weekly', $content, 'Missing Bangkok Weekly Lottery');
        $this->assertStringContainsString('6 Ball', $content);
    }

    public function test_discounts_page_has_safe_calculation_note(): void
    {
        $content = (string) $this->get('/discounts')->assertOk()->getContent();

        $this->assertStringContainsString('server-calculated', strtolower($content).' server-authoritative', 'Missing server-authoritative safety note');
        // The TH table must warn that browser cannot supply rates
        $this->assertStringContainsString('LottoDiscountCalculator', $content);
    }
}
