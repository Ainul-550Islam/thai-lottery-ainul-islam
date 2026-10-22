<?php

declare(strict_types=1);

namespace Tests\Unit\Lang;

use PHPUnit\Framework\TestCase;

/**
 * Keeps unverifiable marketing claims out of the public homepage copy in
 * English and Thai. Each banned phrase is a claim the codebase cannot prove
 * (licensing, certification, guaranteed/instant payouts, 24/7 VIP service,
 * native apps, product lines that are not implemented, bank-grade standards).
 */
final class HomeClaimsGuardTest extends TestCase
{
    private const BANNED_EN = [
        'ISO 27001',
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
        'LINE Official',
        '900x',
        'zero-fee',
        'zero withdrawal',
        'Licensed',
        'licensed',
        'Luxury',
        'Premier',
        'native iOS',
        'Instant Payout',
        'Instant Web App',
        'Instant zero-fee',
        'Instant biometric',
        '24/7',
        '88 daily',
        '88 Daily',
        '4D',
        'Speed Round',
        'speed round',
        'Wagering',
        'Thailand\'s official',
        'GLO Thailand & Asia',
        'Government Lottery Office Thailand',
        'cryptographic',
        'Live Chat',
        '150,000,000',
        'Lao & Hanoi',
        'Direct synchronized feed',
        'automated',
    ];

    /** Thai claim phrases that were removed from lang/th/home.php. */
    private const BANNED_TH = [
        'การันตี',
        'ไม่มีค่าธรรมเนียม',
        'มาตรฐานสถาบันการเงิน',
        'มั่นใจได้ 100%',
        'VIP',
        '24 ชั่วโมง',
        '24 ชม.',
        'ตลอด 24',
        'ยี่กี 88',
        'หวยลาว',
        'หวยฮานอย',
        'ระดับพรีเมียม',
        'มาตรฐานสากล',
        'สุดยอดแพลตฟอร์ม',
        'อย่างเป็นทางการ',
        'กองสลาก',
        'รองรับมือถือเต็มรูปแบบ',
        'อัตโนมัติ',
        'เงินเข้าทันที',
        'ไม่มีขั้นต่ำ',
        '256-bit',
        'ระบบความปลอดภัยระดับสูง',
        'ดาวน์โหลดแอปพลิเคชันสำหรับ iOS และ Android',
    ];

    /** @return array<string, string> */
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

    private static function load(string $locale): array
    {
        return require dirname(__DIR__, 3).'/lang/'.$locale.'/home.php';
    }

    public function test_english_homepage_copy_contains_no_banned_claim(): void
    {
        $violations = [];
        foreach (self::flatten(self::load('en')) as $key => $value) {
            foreach (self::BANNED_EN as $phrase) {
                if (str_contains($value, $phrase)) {
                    $violations[] = "$key contains \"$phrase\"";
                }
            }
        }

        $this->assertSame([], $violations);
    }

    public function test_thai_homepage_copy_contains_no_banned_claim(): void
    {
        $violations = [];
        foreach (self::flatten(self::load('th')) as $key => $value) {
            foreach (self::BANNED_TH as $phrase) {
                if (str_contains($value, $phrase)) {
                    $violations[] = "$key contains \"$phrase\"";
                }
            }
        }

        $this->assertSame([], $violations);
    }

    public function test_thai_file_still_defines_every_key_that_english_defines(): void
    {
        $en = array_keys(self::flatten(self::load('en')));
        $th = self::flatten(self::load('th'));

        $missing = array_values(array_filter($en, fn (string $k): bool => ! array_key_exists($k, $th)));

        $this->assertSame([], $missing, 'Thai homepage file lost keys that English defines');
    }

    public function test_english_homepage_still_has_its_structural_keys(): void
    {
        $en = self::flatten(self::load('en'));

        foreach (['meta_title', 'hero_title', 'cta_results', 'hero.title_highlight', 'trust.title', 'payments.title'] as $key) {
            $this->assertArrayHasKey($key, $en);
            $this->assertNotSame('', trim($en[$key]));
        }
    }
}
