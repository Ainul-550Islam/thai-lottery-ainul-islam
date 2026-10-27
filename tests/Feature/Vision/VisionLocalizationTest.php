<?php

declare(strict_types=1);

namespace Tests\Feature\Vision;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VisionLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_page_localization_files_have_exact_key_parity(): void
    {
        $english = (array) require base_path('lang/en/public_pages.php');
        $thai = (array) require base_path('lang/th/public_pages.php');
        $bangla = (array) require base_path('lang/bn/public_pages.php');

        $this->assertSame(array_keys($english), array_keys($thai));
        $this->assertSame(array_keys($english), array_keys($bangla));
        foreach (['vision_hero_title', 'clear_title', 'governance_display_title', 'journey_future_text'] as $key) {
            $this->assertNotSame('', trim((string) $english[$key]));
            $this->assertNotSame('', trim((string) $thai[$key]));
            $this->assertNotSame('', trim((string) $bangla[$key]));
        }
    }

    public function test_vision_static_interface_renders_in_thai_and_bangla(): void
    {
        $this->get('/vision?lang=th')
            ->assertOk()
            ->assertSee('กลยุทธ์และความไว้วางใจ')
            ->assertSee('ระบบคุณค่าของแพลตฟอร์ม');

        $this->get('/vision?lang=bn')
            ->assertOk()
            ->assertSee('কৌশল ও আস্থা')
            ->assertSee('প্ল্যাটফর্মের মূল্যবোধ')
            ->assertSee('প্রতিটি পদক্ষেপে আস্থা গড়ে তুলুন')
            ->assertSee('আস্থা একটি চর্চা, কোনো ব্যাজ নয়')
            ->assertSee('<title>দৃষ্টিভঙ্গি ও লক্ষ্য', false);
    }

    public function test_vision_page_renders_thai_content_when_thai_is_configured(): void
    {
        $this->app->setLocale('th');
        $response = $this->get(route('vision'));

        $response->assertOk();
        $response->assertSee('วิสัยทัศน์และพันธกิจ');
        $response->assertSee('กรอบคุณค่า 5 ด้าน');
        $response->assertSee('ธรรมาภิบาลและความไว้วางใจ');
    }
}
