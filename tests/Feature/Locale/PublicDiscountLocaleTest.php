<?php

declare(strict_types=1);

namespace Tests\Feature\Locale;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PublicDiscountLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_discount_controller_accepts_the_supported_bangla_locale(): void
    {
        $this->get('/discounts?locale=bn')
            ->assertOk()
            ->assertViewHas('discounts', fn (array $discounts): bool => ($discounts['locale'] ?? null) === 'bn');
    }

    public function test_discount_static_interface_renders_in_thai_and_bangla(): void
    {
        $this->get('/discounts?lang=th')
            ->assertOk()
            ->assertSee('รายการส่วนลด')
            ->assertSee('ราคาคำนวณโดยเซิร์ฟเวอร์');

        $this->get('/discounts?lang=bn')
            ->assertOk()
            ->assertSee('ছাড়ের তালিকা')
            ->assertSee('সার্ভার-নির্ধারিত মূল্য');

        $this->get('/prize-verification?lang=bn')
            ->assertOk()
            ->assertSee('পুরস্কার যাচাই')
            ->assertSee('এই অনলাইন অনুসন্ধান মূল কাগজের টিকিট পরীক্ষা করে না।');
    }

    public function test_verification_result_and_discount_table_use_bangla_overrides(): void
    {
        $keys = [
            'discount_meta_title', 'discount_no_rules', 'discount_table_rule',
            'discount_table_market', 'discount_table_type', 'discount_table_value',
            'discount_table_minimum', 'discount_table_maximum', 'discount_table_period',
            'discount_table_stackable', 'discount_table_version', 'discount_type_percentage',
            'discount_type_fixed', 'discount_market_any', 'discount_stackable_yes',
            'discount_stackable_no', 'discount_period_open', 'discount_period_from',
            'discount_period_between', 'discount_no_limit', 'immutable_badge',
            'affiliate_heading', 'affiliate_note', 'affiliate_band', 'affiliate_rate',
            'affiliate_eligibility', 'affiliate_not_published', 'affiliate_contact', 'affiliate_band_standard',
            'affiliate_band_partner', 'affiliate_band_internal',
            'affiliate_eligibility_standard', 'affiliate_eligibility_partner',
            'affiliate_eligibility_internal',
            'result_heading', 'result_checked_at', 'result_reference', 'result_query_echo',
            'result_product', 'result_draw', 'result_draw_date', 'result_prize_category',
            'result_prize_amount', 'result_matches', 'result_no_matches', 'value_unavailable',
            'reason_heading', 'reason_draw_reference_required', 'reason_not_six_digits',
            'reason_invalid_payload', 'reason_unsupported_format', 'reason_verification_disabled',
            'reason_no_published_draw', 'status_not_found', 'status_not_found_detail',
            'status_found_not_winning', 'status_found_not_winning_detail', 'status_winning',
            'status_winning_detail', 'status_payment_hold', 'status_payment_hold_detail',
            'status_paid', 'status_paid_detail', 'status_invalid', 'status_invalid_detail',
            'status_unavailable', 'status_unavailable_detail', 'authenticity_heading',
            'authenticity_digital_record_verified', 'authenticity_digital_record_verified_detail',
            'authenticity_public_record_found', 'authenticity_public_record_found_detail',
            'authenticity_not_verified', 'authenticity_not_verified_detail', 'authenticity_revoked',
            'authenticity_revoked_detail', 'authenticity_paper_disclaimer', 'barcode_heading',
            'barcode_supported', 'barcode_not_configured', 'barcode_unsupported_format',
            'barcode_invalid', 'barcode_fixture_notice', 'privacy_notice',
        ];

        foreach ($keys as $key) {
            $this->assertNotSame(
                trans('prize_discount.'.$key, [], 'en'),
                trans('prize_discount.'.$key, [], 'bn'),
                'Bangla must override prize_discount.'.$key,
            );
        }
    }

    public function test_public_discount_page_renders_only_published_affiliate_bands(): void
    {
        config(['discounts.affiliate_display.publish' => true]);

        $this->get('/discounts?lang=th&locale=th')
            ->assertOk()
            ->assertSee('ระดับค่าคอมมิชชันพันธมิตร')
            ->assertSee('1.00%')
            ->assertSee('มาตรฐาน')
            ->assertSee('ติดต่อสอบถามคุณสมบัติการเป็นพันธมิตร')
            ->assertSee('href="#affiliate-commissions"', false);

        $this->get('/discounts?lang=bn&locale=bn')
            ->assertOk()
            ->assertSee('অ্যাফিলিয়েট কমিশনের স্তর')
            ->assertSee('1.00%')
            ->assertSee('সাধারণ')
            ->assertSee('অ্যাফিলিয়েট যোগ্যতা সম্পর্কে যোগাযোগ করুন');
    }

    public function test_unpublished_affiliate_bands_are_not_rendered(): void
    {
        config(['discounts.affiliate_display.publish' => false]);

        $this->get('/discounts?lang=bn&locale=bn')
            ->assertOk()
            ->assertSee('এই মুহূর্তে অ্যাফিলিয়েট কমিশনের কোনো স্তর প্রকাশিত নেই।')
            ->assertDontSee('1.00%');
    }

    public function test_public_discount_api_includes_only_published_affiliate_bands(): void
    {
        config(['discounts.affiliate_display.publish' => true]);

        $this->getJson('/api/v1/public/lotto-discount/catalogue')
            ->assertOk()
            ->assertJsonPath('affiliate.published', true)
            ->assertJsonCount(2, 'affiliate.bands')
            ->assertJsonMissing(['band' => 'internal_pilot']);
    }

    public function test_lotto_discount_alias_renders_the_same_canonical_discount_page(): void
    {
        $this->get('/lotto-discount')
            ->assertOk()
            ->assertSee('data-next-public-page="discounts"', false)
            ->assertSee('DISCOUNT CATALOGUE', false);
    }
}
