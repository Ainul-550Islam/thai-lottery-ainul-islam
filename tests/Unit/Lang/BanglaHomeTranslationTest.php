<?php

declare(strict_types=1);

namespace Tests\Unit\Lang;

use PHPUnit\Framework\TestCase;

/**
 * Guards lang/bn/home.php:
 *  1. every key exists in English (no invented keys),
 *  2. placeholders match the English string (:count, :current, :last),
 *  3. no unverifiable marketing claim is translated into Bangla.
 */
final class BanglaHomeTranslationTest extends TestCase
{
    /** Phrases from lang/en/home.php that the codebase cannot back up. */
    private const BANNED_CLAIM_PHRASES = [
        'licensed',
        'Licensed',
        'licence',
        'ISO 27001',
        'Certified',
        'Guarantee',
        'guarantee',
        'provably',
        'Provably',
        'Institutional',
        'VIP',
        'Concierge',
        'iGaming',
        'bank-grade',
        'Telegram',
        'LINE',
        '900x',
        'zero-fee',
        'zero withdrawal',
        'Instant',
        'instant',
        'automated',
        'Automated',
        'Official',
        'official',
        'সরকারি',
        'Government',
        'Thailand\'s',
        '24/7',
    ];

    /** @return array<string, mixed> */
    private static function load(string $locale): array
    {
        $path = dirname(__DIR__, 3).'/lang/'.$locale.'/home.php';
        self::assertFileExists($path);

        return require $path;
    }

    /** @return array<string, string> dotted key => value */
    private static function flatten(array $items, string $prefix = ''): array
    {
        $out = [];
        foreach ($items as $key => $value) {
            $full = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $out += self::flatten($value, $full);
            } else {
                $out[$full] = (string) $value;
            }
        }

        return $out;
    }

    public function test_every_bangla_key_exists_in_english(): void
    {
        $en = self::flatten(self::load('en'));
        $bn = self::flatten(self::load('bn'));

        $unknown = array_diff_key($bn, $en);

        $this->assertSame([], array_keys($unknown), 'Bangla file has keys absent from English.');
        $this->assertNotEmpty($bn);
    }

    public function test_placeholders_match_english_for_every_translated_key(): void
    {
        $en = self::flatten(self::load('en'));
        $bn = self::flatten(self::load('bn'));

        $mismatches = [];
        foreach ($bn as $key => $value) {
            preg_match_all('/:[a-z_]+/', $en[$key], $enPlaceholders);
            preg_match_all('/:[a-z_]+/', $value, $bnPlaceholders);
            sort($enPlaceholders[0]);
            sort($bnPlaceholders[0]);
            if ($enPlaceholders[0] !== $bnPlaceholders[0]) {
                $mismatches[] = $key;
            }
        }

        $this->assertSame([], $mismatches);
    }

    public function test_home_feature_copy_used_by_views_has_bangla_translations(): void
    {
        $en = self::flatten(self::load('en'));
        $bn = self::flatten(self::load('bn'));
        $keys = [
            'countdown.aria_label',
            'countdown.label',
            'latest_results.subtitle',
            'latest_results.first_3_digits',
            'latest_results.last_3_digits',
            'latest_results.last_2_digits',
            'navigation_label',
            'result_version_label',
            'current_result_unavailable',
            'result_status_verified',
            'result_status_available',
            'result_status_missing',
            'result_status_unavailable',
            'section_status.available',
            'section_status.unavailable',
            'section_status.catalogue_only',
            'section_status.configured',
            'section_status.not_configured',
            'source_status_verified',
            'source_status_configured',
            'source_status_reconciled',
            'source_status_fixture',
            'source_status_not_configured',
            'source_status_unavailable',
            'live_status_configured',
            'live_status_not_configured',
            'live_status_unavailable',
            'live_unavailable',
            'live_iframe_title',
            'stream_provider_label',
            'stream_provider_unspecified',
            'stream_url_disclaimer',
            'replay_catalogue_status',
            'ticket_price_label',
            'digits_label',
            'no_products_published',
            'product_name_generic',
            'product_l6_name',
            'product_n3_name',
            'product_l6_note',
            'product_n3_note',
            'payment_method_stripe',
            'payment_method_bkash',
            'payment_method_nagad',
            'payment_method_crypto',
            'payment_method_bank_transfer',
            'payment_method_manual',
            'support_help',
            'support_contact',
            'support_not_configured',
            'prize_amount_label',
            'app.title',
            'app.subtitle',
            'app.ios_btn',
            'app.android_btn',
            'app.pwa_btn',
            'trust.feature_1_title',
            'trust.feature_1_desc',
            'trust.feature_2_title',
            'trust.feature_2_desc',
            'trust.feature_3_title',
            'trust.feature_3_desc',
            'trust.feature_4_title',
            'trust.feature_4_desc',
        ];

        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $bn, 'Missing Bangla Home translation: '.$key);
            $this->assertNotSame($en[$key], $bn[$key], 'Home copy still falls back to English: '.$key);
        }
    }

    public function test_no_unverifiable_claim_is_translated(): void
    {
        $bn = self::flatten(self::load('bn'));

        $violations = [];
        $stateOrDisclaimerCopy = [
            'independent_disclaimer',
            'source_status_verified',
            'source_status_configured',
        ];
        foreach ($bn as $key => $value) {
            // These strings explicitly negate affiliation or describe the
            // result source's recorded state; the words alone are not claims.
            if (in_array($key, $stateOrDisclaimerCopy, true)) {
                continue;
            }
            foreach (self::BANNED_CLAIM_PHRASES as $phrase) {
                if (str_contains($value, $phrase)) {
                    $violations[] = sprintf('%s contains "%s"', $key, $phrase);
                }
            }
        }

        $this->assertSame([], $violations);
    }

    public function test_all_home_composition_labels_used_by_the_live_home_service_are_translated(): void
    {
        $bn = self::flatten(self::load('bn'));
        $required = [
            'meta_title', 'meta_description', 'independent_disclaimer', 'hero_title', 'hero_lead',
            'cta_results', 'cta_check', 'cta_sales', 'cta_sign_in', 'cta_register',
            'lane_results_title', 'current_result_title', 'next_draw_title', 'live_title',
            'check_title', 'check_label', 'check_placeholder', 'check_button', 'check_help',
            'sales_title', 'sales_help', 'products_title', 'prize_title', 'stats_title',
            'trust_title', 'bonuses_title', 'payments_title', 'support_title', 'app_title',
            'stats_registered_accounts', 'stats_published_results',
            'stats_active_sales_point_records', 'stats_completed_draws', 'stats_active_members',
            'nav_home', 'nav_results', 'nav_ticket_check', 'nav_sales_points',
            'nav_national_lottery', 'nav_weekly_lottery', 'nav_bingo', 'nav_pcso',
        ];

        $missing = array_values(array_diff($required, array_keys($bn)));
        $this->assertSame([], $missing, 'Active Home labels missing Bangla translations.');
    }

    public function test_bangla_strings_are_actually_bangla(): void
    {
        $bn = self::flatten(self::load('bn'));

        $nonBangla = [];
        foreach (['meta_title', 'hero_title', 'cta_results', 'cta_check', 'check_button', 'nav_home', 'nav_ticket_check', 'nav_sales_points'] as $key) {
            // Bangla Unicode block U+0980–U+09FF must be present.
            if (preg_match('/[\x{0980}-\x{09FF}]/u', $bn[$key]) !== 1) {
                $nonBangla[] = $key;
            }
        }

        $this->assertSame([], $nonBangla);
    }
}
