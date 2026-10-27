<?php

declare(strict_types=1);

namespace Tests\Unit\Lang;

use PHPUnit\Framework\TestCase;

/**
 * Public copy must not claim government status, legal fixing of prices, or
 * an "official" badge or technical compatibility claim the platform cannot prove.
 */
final class GloCopyNeutralityTest extends TestCase
{
    /** @var array<string, list<string>> */
    private const BANNED_IN_FILE = [
        'lang/en/prize_discount.php' => [
            'fixed by law',
            'Government Lottery Office ticket prices',
            'GLO-compatible',
        ],
        'lang/th/prize_discount.php' => [
            'ตามกฎหมาย',
            'รองรับรูปแบบ GLO',
        ],
        'lang/th/home.php' => [
            'มาตรฐานสากล',
            'การันตี',
            '100%',
            '24 ชั่วโมง',
            '88 รอบ',
            'หวยลาว',
            'หวยฮานอย',
        ],
        'resources/views/auth/register.blade.php' => [
            'Fast Registration',
            'premier verified digital lottery platform',
            'instant payouts',
        ],
        'resources/views/notifications/kyc-status.blade.php' => [
            'within 24 hours',
            'full unconstrained betting',
            'international AML/CTF standards',
        ],
        'app/Notifications/KycStatusNotification.php' => [
            'fully approved for enhanced limits and withdrawals',
            'Document unreadable or invalid format.',
        ],
        'resources/views/components/home/lottery-grid.blade.php' => [
            'GLO Official',
            'Government Lottery L6',
        ],
        'lang/en/vision.php' => [
            'SEC COMPLIANT',
            'ISO/IEC 27001',
            'FATF ALIGNED',
            'GDPR READY',
            'Licensed & Regulated Operating Framework',
        ],
        'lang/th/vision.php' => [
            'มาตรฐาน ก.ล.ต.',
            'ISO/IEC 27001',
            'มาตรฐาน FATF',
            'พร้อมรองรับ GDPR',
            'ถูกต้องตามกฎหมาย',
        ],
        'lang/en/home.php' => [
            'Government Lottery Results',
            'Government Lottery Office Thailand',
            'Government Lottery L6',
            'licensed online lottery platform',
            '900x payouts',
            'bank-grade cryptographic security',
            'Instant Payout Guarantee',
            'ISO 27001 Certified Security',
            '24/7 VIP Concierge',
        ],
        'resources/views/deposit/index.blade.php' => ['Secure deposit access'],
        'resources/views/withdrawal/index.blade.php' => ['Secure withdrawal access'],
        'resources/views/betting/index.blade.php' => ['Open secure bet slip', 'Canonical betting access'],
        'resources/views/home.blade.php' => ['Draw rules and prize tables follow Government Lottery Office publications'],
        'config/home.php' => [
            'Server-side authorization on every financial action',
            'Idempotent wallet and payout operations',
            'Append-only audit trail for money movements',
            'Webhook signature verification before settlement',
            'Transaction-safe ledger writes with row locks',
            'KYC-controlled withdrawals above the configured threshold',
        ],

        'app/Http/Controllers/HomeController.php' => [
            'Government Lottery results, ticket checking tools, and wagering platform',
        ],
        'app/Services/Lottery/GloPublicHomeService.php' => [
            'Government Lottery L6',
            'official_full_sale',
            'Official full-sale prize schedule',
            'Sales-based prize pool; payouts are draw-calculated',
        ],
    ];

    public function test_public_copy_contains_no_banned_government_claim(): void
    {
        $violations = [];

        foreach (self::BANNED_IN_FILE as $relative => $phrases) {
            $path = dirname(__DIR__, 3).'/'.$relative;
            $this->assertFileExists($path);
            $contents = (string) file_get_contents($path);

            foreach ($phrases as $phrase) {
                if (str_contains($contents, $phrase)) {
                    $violations[] = "$relative contains \"$phrase\"";
                }
            }
        }

        $this->assertSame([], $violations);
    }

    public function test_prize_discount_copy_keeps_the_non_affiliation_notice(): void
    {
        $lang = require dirname(__DIR__, 3).'/lang/en/prize_discount.php';

        $this->assertArrayHasKey('not_official_notice', $lang);
        $this->assertStringContainsString('not the Government Lottery Office', $lang['not_official_notice']);
        $this->assertStringContainsString('Independent platform lookup', $lang['verify_meta_description']);
        $this->assertStringNotContainsString('GLO-compatible', $lang['verify_meta_description']);
        $this->assertArrayHasKey('immutable_notice', $lang);
        $this->assertStringNotContainsString('fixed by law', $lang['immutable_notice']);
    }
}
