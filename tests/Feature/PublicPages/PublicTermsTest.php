<?php

declare(strict_types=1);

namespace Tests\Feature\PublicPages;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * PROMPT 2 — PublicTermsTest (18 assertion groups):
 * Versioned static Terms (config legal.version / effective_at — never dynamic),
 * L6/N3 economics from config, stamp duty without withholding regression,
 * product separation, careful one-account/verification/age/ownership wording,
 * responsible gaming limited to implemented features, neutral legal disclaimer.
 */
final class PublicTermsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function termsContent(?string $locale = null): string
    {
        if ($locale !== null) {
            app()->setLocale($locale);
        }

        return (string) $this->get('/terms')->assertOk()->getContent();
    }

    // 1
    public function test_terms_is_public_without_login(): void
    {
        $this->get('/terms')->assertOk();
    }

    // 2
    public function test_version_comes_from_config_and_is_v1_0_0_default(): void
    {
        $this->assertSame('v1.0.0', config('legal.version'));
        $content = $this->termsContent();
        $this->assertStringContainsString('data-pp-legal-version="v1.0.0"', $content);
        $this->assertStringContainsString('data-pp-version', $content);
        $this->assertStringContainsString('v1.0.0', $content);
    }

    // 3
    public function test_effective_date_is_static_from_config_not_dynamic_per_request(): void
    {
        $effective = (string) config('legal.effective_at');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $effective);
        $content = $this->termsContent();
        $this->assertStringContainsString('data-pp-effective', $content);
        $this->assertStringContainsString($effective, $content);
        // Never render "today" as the effective stamp.
        $this->assertStringNotContainsString(
            'Last updated: '.date('Y-m-d').'</',
            str_replace($effective, '', $content),
        );
        $content2 = $this->termsContent();
        $this->assertStringContainsString((string) config('legal.updated_at'), $content2);
    }

    // 4
    public function test_l6_ticket_price_is_80_from_config(): void
    {
        $this->assertSame('80.00', (string) config('glo.l6.ticket_price'));
        $content = $this->termsContent();
        $this->assertStringContainsString('80.00', $content);
        $this->assertStringNotContainsString('40.00', $content);
        $this->assertStringNotContainsString('40 THB', $content);
    }

    // 5
    public function test_l6_allocation_units_and_prize_count_from_config(): void
    {
        $content = $this->termsContent();
        // 48,000,000 allocation, 14,168 prizes, 1,000,000 units — grouped display.
        $this->assertStringContainsString('48,000,000', $content);
        $this->assertStringContainsString('14,168', $content);
        $this->assertStringContainsString('1,000,000', $content);
        $this->assertStringNotContainsString('3,000,000', $content);
        $this->assertStringNotContainsString('3000000', $content);
    }

    // 6
    public function test_l6_proportional_scaling_language_present(): void
    {
        $content = $this->termsContent();
        $this->assertStringContainsString('proportionally', $content);
        $this->assertStringContainsString('proportional', $content);
    }

    // 7
    public function test_n3_price_and_pool_rate_from_config(): void
    {
        $this->assertSame('20.00', (string) config('glo.n3.ticket_price'));
        $this->assertSame('0.60', (string) config('glo.n3.pool_rate'));
        $content = $this->termsContent();
        $this->assertStringContainsString('20.00', $content);
        $this->assertStringContainsString('60%', $content);
    }

    // 8
    public function test_n3_payouts_are_draw_calculated_not_fixed(): void
    {
        $content = $this->termsContent();
        $this->assertStringContainsString('draw-calculated', $content);
        $this->assertStringContainsString('variable', $content);
        // Banned fixed N3 payout tables never appear.
        foreach (['3686', '749', '531', '252116'] as $banned) {
            $this->assertStringNotContainsString($banned, $content);
        }
    }

    // 9
    public function test_stamp_duty_is_1_per_200_or_fraction(): void
    {
        $content = $this->termsContent();
        $this->assertStringContainsString('ceil(gross/200)', $content);
        $this->assertStringContainsString('Stamp duty', $content);
        $this->assertStringContainsString('fraction', $content);
        // Config-backed unit and divisor present as numbers.
        $this->assertSame('200', (string) config('glo.stamp_duty.divisor'));
        $this->assertSame('1.00', (string) config('glo.stamp_duty.per_unit_baht'));
    }

    // 10
    public function test_income_tax_exempt_and_no_withholding_regression(): void
    {
        $this->assertTrue((bool) config('glo.stamp_duty.income_tax_exempt'));
        $content = $this->termsContent();
        $this->assertStringContainsString('income tax is exempt', $content);
        // Explicit non-regression: no 0.5% / 1% withholding model advertised as active.
        $this->assertStringNotContainsString('withholding of 0.5%', $content);
        $this->assertStringNotContainsString('withholding of 1%', $content);
        $this->assertStringContainsString('No 0.5% or 1% withholding', $content);
    }

    // 11
    public function test_product_separation_glo_vs_operator_markets(): void
    {
        $content = $this->termsContent();
        $this->assertStringContainsString('3D', $content);
        $this->assertStringContainsString('TOD', $content);
        $this->assertStringContainsString('2D', $content);
        $this->assertStringContainsString('Run', $content);
        $this->assertStringContainsString('never described as GLO N3', $content);
        // Generic markets never labelled as GLO N3.
        $this->assertDoesNotMatchRegularExpression('/GLO\s*N3[^<\n]{0,40}(3D|TOD|2D|Run)/i', $content);
        $this->assertDoesNotMatchRegularExpression('/(3D|TOD|2D|Run)[^<\n]{0,40}GLO\s*N3/i', $content);
    }

    // 12
    public function test_one_account_policy_uses_enforced_where_language_only(): void
    {
        $content = $this->termsContent();
        $this->assertStringContainsString('One account per verified user where enforced', $content);
        $this->assertStringContainsString('email, username, and phone are unique', $content);
        // No hard one-per-person identity claim.
        $this->assertStringNotContainsString('exactly one account per person', $content);
        $this->assertStringNotContainsString('one account per human', $content);
    }

    // 13
    public function test_verification_wording_may_vary_by_account_product_channel(): void
    {
        $content = $this->termsContent();
        $this->assertStringContainsString('may vary by account, product, and channel', $content);
        $this->assertStringNotContainsString('everyone must verify the same way', $content);
    }

    // 14
    public function test_age_rules_split_registration_purchase_claim(): void
    {
        $content = $this->termsContent();
        $this->assertStringContainsString('Registration:', $content);
        $this->assertStringContainsString('Purchase:', $content);
        $this->assertStringContainsString('Claim:', $content);
        $this->assertStringContainsString('20', $content);
        $this->assertStringContainsString('date of birth', $content);
        $this->assertStringContainsString('verified date of birth', $content);
        // Claim age comes from config (20).
        $this->assertSame(20, (int) config('glo.claims.min_claimant_age'));
    }

    // 15
    public function test_ticket_ownership_and_no_transferable_l6_claim(): void
    {
        $content = $this->termsContent();
        $this->assertStringContainsString('not freely transferable', $content);
        $this->assertStringContainsString('leading zeros', $content);
        $this->assertStringNotContainsString('tickets are freely transferable', $content);
        $this->assertStringNotContainsString('transferable claim', $content);
    }

    // 16
    public function test_responsible_gaming_references_only_implemented_features(): void
    {
        $content = $this->termsContent();
        $this->assertStringContainsString('self-exclusion', $content);
        $this->assertStringContainsString('limits', $content);
        $this->assertStringContainsString('reality checks', $content);
        // No regulatory-compliance endorsement claims.
        $this->assertStringNotContainsString('regulator-approved', $content);
        $this->assertStringNotContainsString('gambling commission', $content);
        $this->assertStringNotContainsString('licensed by the government', $content);
    }

    // 17
    public function test_claim_rules_have_no_immediate_payment_promise(): void
    {
        $content = $this->termsContent();
        $this->assertStringContainsString('no promise of immediate payment', $content);
        $this->assertStringContainsString('KYC', $content);
        $this->assertStringContainsString('holds', $content);
        $this->assertStringContainsString('approved payout', $content);
        $this->assertStringContainsString('stamp duty', $content);
        $this->assertStringNotContainsString('instant payout guaranteed', $content);
        $this->assertStringNotContainsString('paid immediately', $content);
    }

    // 18
    public function test_legal_disclaimer_independent_platform_and_language_parity(): void
    {
        $content = $this->termsContent();
        $this->assertStringContainsString('independent platform', $content);
        $this->assertStringContainsString('not the official GLO website', $content);
        $this->assertStringContainsString('do not claim government ownership', $content);
        $this->assertStringNotContainsString('thailotto.club', $content);
        $this->assertStringNotContainsString('Thailotto', $content);
        $this->assertStringNotContainsString('40.00', $content);

        $en = (array) require base_path('lang/en/public_pages.php');
        $th = (array) require base_path('lang/th/public_pages.php');
        $this->assertSame(array_keys($en), array_keys($th));

        // Cache-control: safe public cache on guest legal GET; never private.
        $response = $this->get('/terms');
        $response->assertOk();
        $cc = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cc);
        $this->assertStringContainsString('max-age=', $cc);
        $this->assertStringNotContainsString('private', $cc);
    }
}
