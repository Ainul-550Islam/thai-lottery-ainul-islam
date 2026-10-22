<?php

declare(strict_types=1);

namespace Tests\Feature\About;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AboutLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pages_localization_files_have_exact_key_parity(): void
    {
        $english = (array) require base_path('lang/en/public_pages.php');
        $thai = (array) require base_path('lang/th/public_pages.php');

        $this->assertSame(array_keys($english), array_keys($thai));
        $this->assertNotEmpty($english['about_hero_title']);
        $this->assertNotEmpty($thai['about_hero_title']);
        $this->assertNotEmpty($english['about_timeline.1939.description']);
        $this->assertNotEmpty($thai['about_timeline.1939.description']);
    }

    public function test_about_static_interface_renders_in_thai_and_bangla(): void
    {
        $this->get('/about?lang=th')
            ->assertOk()
            ->assertSee('สำรวจเรื่องราว')
            ->assertSee('แพลตฟอร์มอิสระ');

        $this->get('/about?lang=bn')
            ->assertOk()
            ->assertSee('আমাদের সম্পর্কে')
            ->assertSee('একটি স্বাধীন প্ল্যাটফর্ম')
            ->assertSee('<title>প্ল্যাটফর্ম সম্পর্কে', false);
    }

    public function test_about_page_renders_the_configured_locale(): void
    {
        $this->app->setLocale('th');
        $response = $this->get(route('about'));

        $response->assertOk();
        $response->assertSee('เกี่ยวกับแพลตฟอร์มนี้');
        $response->assertSee('ประวัติลอตเตอรี่ในประเทศไทย');
    }
}
