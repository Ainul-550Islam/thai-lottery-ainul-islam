<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The shared public footer must never print its own translation keys.
 *
 * WHY THIS TEST EXISTS
 * resources/views/components/public-page/footer.blade.php resolves every label
 * through public_pages.footer_*. Those keys existed only in lang/bn: lang/en and
 * lang/th had none of the 27 of them, so every English and Thai public page -
 * the home page included - rendered its footer as a grid of strings like
 * "public_pages.footer_about" and "public_pages.footer_terms".
 *
 * A missing key is not noisy enough on its own to be noticed behind a styled
 * footer, so the assertion is explicit: the rendered HTML may not contain the
 * namespace at all.
 *
 * SCOPE NOTE
 * Bangla is deliberately not asserted here. `?lang=bn` is ignored by
 * App\Http\Middleware\SetLocale, whose allow-list is still hard-coded to
 * ['en', 'th'] even though App\Support\SupportedLocale lists `bn`. That is
 * backend middleware, recorded in HANDOFF-A.md; this test proves the views and
 * the en/th catalogues, which is what Agent A owns.
 */
final class PublicFooterLocalizationTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function pageProvider(): array
    {
        return [
            'home' => ['/'],
            'services' => ['/services'],
            'fees' => ['/fees'],
            'terms' => ['/terms'],
        ];
    }

    #[DataProvider('pageProvider')]
    public function test_no_public_page_renders_a_raw_footer_translation_key(string $path): void
    {
        $html = (string) $this->get($path)->assertOk()->getContent();

        $this->assertStringNotContainsString(
            'public_pages.footer_',
            $html,
            $path.' rendered a raw public_pages.footer_* key; the footer catalogue is incomplete.',
        );
    }

    public function test_the_footer_is_translated_in_english_and_thai(): void
    {
        $english = (string) $this->get('/services')->assertOk()->getContent();
        $this->assertStringContainsString(trans('public_pages.footer_about', [], 'en'), $english);
        $this->assertStringContainsString(trans('public_pages.footer_disclaimer', [], 'en'), $english);

        $thai = (string) $this->get('/services?lang=th')->assertOk()->getContent();
        $this->assertStringContainsString(trans('public_pages.footer_about', [], 'th'), $thai);
        $this->assertStringContainsString(trans('public_pages.footer_disclaimer', [], 'th'), $thai);
        $this->assertSame('เกี่ยวกับเรา', trans('public_pages.footer_about', [], 'th'));
    }

    public function test_english_and_thai_footer_catalogues_are_symmetric(): void
    {
        $en = require lang_path('en/public_pages.php');
        $th = require lang_path('th/public_pages.php');

        $this->assertIsArray($en);
        $this->assertIsArray($th);

        $enFooter = array_values(array_filter(array_keys($en), static fn ($key): bool => str_starts_with((string) $key, 'footer_')));
        $thFooter = array_values(array_filter(array_keys($th), static fn ($key): bool => str_starts_with((string) $key, 'footer_')));

        $this->assertGreaterThanOrEqual(20, count($enFooter), 'The footer catalogue shrank; the footer would fall back to raw keys again.');
        $this->assertSame($enFooter, $thFooter, 'lang/en and lang/th disagree about which footer keys exist.');
    }
}
