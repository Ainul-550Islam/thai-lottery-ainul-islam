<?php

declare(strict_types=1);

namespace Tests\Unit\Lang;

use PHPUnit\Framework\TestCase;

/**
 * Public copy must not claim government status, legal fixing of prices, or
 * an "official" badge the platform cannot prove. Neutral "GLO-compatible"
 * wording is kept where tests and the non-affiliation notice rely on it.
 */
final class GloCopyNeutralityTest extends TestCase
{
    /** @var array<string, list<string>> */
    private const BANNED_IN_FILE = [
        'lang/en/prize_discount.php' => [
            'fixed by law',
            'Government Lottery Office ticket prices',
        ],
        'lang/th/prize_discount.php' => [
            'ตามกฎหมาย',
        ],
        'resources/views/components/home/lottery-grid.blade.php' => [
            'GLO Official',
            'Government Lottery L6',
        ],
        'lang/en/home.php' => [
            'Government Lottery Results',
            'Government Lottery Office Thailand',
            'Government Lottery L6',
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
        $this->assertStringContainsString('GLO-compatible', $lang['verify_meta_description']);
        $this->assertArrayHasKey('immutable_notice', $lang);
        $this->assertStringNotContainsString('fixed by law', $lang['immutable_notice']);
    }
}
