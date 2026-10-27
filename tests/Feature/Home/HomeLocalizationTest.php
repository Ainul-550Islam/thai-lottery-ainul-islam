<?php

declare(strict_types=1);

namespace Tests\Feature\Home;

use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * Feature tests for Home Localization (Prompt 01).
 * Verifies EN/TH key symmetry and absence of raw translation keys.
 */
final class HomeLocalizationTest extends TestCase
{
    public function test_en_and_th_home_language_files_exist_and_have_parity(): void
    {
        $en = include base_path('lang/en/home.php');
        $th = include base_path('lang/th/home.php');

        $this->assertIsArray($en);
        $this->assertIsArray($th);

        $enKeys = array_keys($en);
        $thKeys = array_keys($th);

        sort($enKeys);
        sort($thKeys);

        $this->assertSame($enKeys, $thKeys, 'EN and TH home translation keys must match exactly');
    }

    public function test_home_page_renders_cleanly_in_thai_locale(): void
    {
        App::setLocale('th');

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('สลากกินแบ่งรัฐบาล', false);

        // Ensure no raw untranslated strings like 'home.' appear on page
        $this->assertStringNotContainsString('home.', (string) $response->getContent());
    }
}
