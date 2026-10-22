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

    public function test_no_unverifiable_claim_is_translated(): void
    {
        $bn = self::flatten(self::load('bn'));

        $violations = [];
        foreach ($bn as $key => $value) {
            foreach (self::BANNED_CLAIM_PHRASES as $phrase) {
                if (str_contains($value, $phrase)) {
                    $violations[] = sprintf('%s contains "%s"', $key, $phrase);
                }
            }
        }

        $this->assertSame([], $violations);
    }

    public function test_bangla_strings_are_actually_bangla(): void
    {
        $bn = self::flatten(self::load('bn'));

        $nonBangla = [];
        foreach (['meta_title', 'hero_title', 'cta_results', 'cta_check', 'check_button'] as $key) {
            // Bangla Unicode block U+0980–U+09FF must be present.
            if (preg_match('/[\x{0980}-\x{09FF}]/u', $bn[$key]) !== 1) {
                $nonBangla[] = $key;
            }
        }

        $this->assertSame([], $nonBangla);
    }
}
