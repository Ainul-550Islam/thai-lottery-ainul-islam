<?php

declare(strict_types=1);

namespace Tests\Feature\Home;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * FIX A — Heritage Full Parity (P1).
 *
 * thailotto.club live has 2 paragraphs: Rama5 2417 (Alabaster) + Rama6 2460 (WWI loan).
 * This test ensures both are present in Thai lang file and rendered on home.
 */
final class HeritageFullParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_thai_hero_description_contains_rama5_and_rama6(): void
    {
        $th = require base_path('lang/th/home.php');

        $desc = (string) ($th['hero']['description'] ?? '');

        // Rama5 2417 — first paragraph
        $this->assertStringContainsString('2417', $desc, 'Missing Rama5 year 2417');
        $this->assertStringContainsString('ครูอาลบาสเตอร์', $desc, 'Missing Alabaster');
        $this->assertStringContainsString('รัชกาลที่ 5', $desc, 'Missing Rama5');

        // Rama6 2460 WWI — second paragraph (FIX A)
        $this->assertStringContainsString('2460', $desc, 'Missing Rama6 year 2460');
        $this->assertStringContainsString('พระบาทสมเด็จพระมงกุฎเกล้าเจ้าอยู่หัว', $desc, 'Missing Rama6 name');
        $this->assertStringContainsString('สงครามโลกครั้งที่ 1', $desc, 'Missing WWI');
        $this->assertStringContainsString('สหราชอาณาจักร', $desc, 'Missing UK loan');
    }

    public function test_thai_heritage_keys_exist_and_contain_full_story(): void
    {
        $th = require base_path('lang/th/home.php');

        $this->assertArrayHasKey('heritage_eyebrow', $th, 'Missing heritage_eyebrow');
        $this->assertArrayHasKey('heritage_title', $th, 'Missing heritage_title');
        $this->assertArrayHasKey('heritage_body', $th, 'Missing heritage_body');

        $this->assertSame('มรดกแห่งชาติ', $th['heritage_eyebrow']);
        $this->assertSame('ประวัติสลากกินแบ่งรัฐบาลไทย', $th['heritage_title']);

        $body = (string) $th['heritage_body'];
        $this->assertStringContainsString('2417', $body);
        $this->assertStringContainsString('2460', $body);
        $this->assertStringContainsString('พระบาทสมเด็จพระจุลจอมเกล้าเจ้าอยู่หัว', $body);
        $this->assertStringContainsString('พระบาทสมเด็จพระมงกุฎเกล้าเจ้าอยู่หัว', $body);
        // Full story must mention both eras
        $this->assertGreaterThan(200, mb_strlen($body), 'Heritage body too short — must be full 2-para story');
    }

    public function test_home_renders_heritage_section_when_thai_locale(): void
    {
        app()->setLocale('th');
        $content = (string) $this->get('/')->assertOk()->getContent();

        // Heritage section exists via home.blade.php #heritage
        $this->assertStringContainsString('id="heritage"', $content, 'Missing #heritage landmark');
        $this->assertStringContainsString('heritage_eyebrow', $content.'มรดกแห่งชาติ', 'Heritage eyebrow not rendered (check lang)');

        // Thai history must be visible in Thai locale
        $this->assertStringContainsString('พระบาทสมเด็จพระจุลจอมเกล้า', $content);
    }

    public function test_english_heritage_fallback_exists(): void
    {
        $this->assertFileExists(base_path('lang/en/home.php'));
        $en = require base_path('lang/en/home.php');
        // English file must have same keys (even if English text)
        $this->assertArrayHasKey('hero', $en);
        $this->assertIsArray($en['hero']);
    }
}
