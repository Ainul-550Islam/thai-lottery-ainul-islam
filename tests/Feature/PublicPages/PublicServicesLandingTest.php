<?php

declare(strict_types=1);

namespace Tests\Feature\PublicPages;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PublicServicesLandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_services_landing_is_public_and_has_canonical_metadata(): void
    {
        $response = $this->get('/services')->assertOk();
        $html = (string) $response->getContent();

        $this->assertStringContainsString('<title>Our Services', $html);
        $this->assertStringContainsString('<meta name="description"', $html);
        $this->assertStringContainsString('<link rel="canonical" href="'.config('app.url').'/services"', $html);
        $this->assertStringContainsString('not operated by or endorsed by a government lottery authority', $html);
    }

    public function test_primary_requested_sections_are_reachable_from_the_home_navigation(): void
    {
        $html = (string) $this->get('/')->assertOk()->getContent();

        foreach (['about', 'services', 'lotto-discount', 'results.index', 'contact'] as $routeName) {
            $this->assertStringContainsString('href="'.route($routeName).'"', $html, 'Home navigation is missing '.$routeName);
        }
    }

    public function test_shared_footer_uses_independent_platform_wording_without_unverified_badges(): void
    {
        $html = (string) $this->get('/services')->assertOk()->getContent();

        $this->assertStringContainsString('Independent platform for lottery information', $html);
        $this->assertStringContainsString('not operated by or endorsed by a government lottery authority', $html);
        $this->assertStringNotContainsString('fintech-grade account management', $html);
        $this->assertStringNotContainsString('Authorized Sales Points', $html);
    }

    public function test_home_footer_disclaims_affiliation_instead_of_saying_glo_rules_apply(): void
    {
        $html = (string) $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('Government Lottery Office rules apply', $html);
        $this->assertStringContainsString('not the official GLO website', $html);
    }

    public function test_services_landing_uses_thai_and_bangla_catalogs(): void
    {
        $this->get('/services?lang=th')
            ->assertOk()
            ->assertSee('บริการของเรา')
            ->assertSee('ข้อมูลสำคัญ:')
            ->assertSee('เกี่ยวกับเรา')
            ->assertSee('ส่วนลดหวย')
            ->assertSee('แพลตฟอร์มอิสระนี้ไม่ได้ดำเนินการหรือรับรองโดยหน่วยงานลอตเตอรี่ของรัฐบาล');

        $this->get('/services?lang=bn')
            ->assertOk()
            ->assertSee('আমাদের সেবাসমূহ')
            ->assertSee('গুরুত্বপূর্ণ:')
            ->assertSee('আমাদের সম্পর্কে')
            ->assertSee('লটারির ছাড়')
            ->assertSee('এই স্বাধীন প্ল্যাটফর্ম কোনো সরকারি লটারি কর্তৃপক্ষ দ্বারা পরিচালিত বা অনুমোদিত নয়');
    }

    public function test_faq_static_interface_renders_in_thai_and_bangla(): void
    {
        $this->get('/faq?lang=th')
            ->assertOk()
            ->assertSee('ศูนย์คำตอบ')
            ->assertSee('ค้นหาคำถามและคำตอบ')
            ->assertSee('ยังไม่พบคำตอบ?');

        $this->get('/faq?lang=bn')
            ->assertOk()
            ->assertSee('উত্তর কেন্দ্র')
            ->assertSee('প্রশ্ন ও উত্তর খুঁজুন')
            ->assertSee('প্রকাশ্য পৃষ্ঠা দেখতে কি অ্যাকাউন্ট দরকার?')
            ->assertSee('উত্তর পাননি?');
    }

    public function test_how_to_play_static_interface_renders_in_thai_and_bangla(): void
    {
        $this->get('/how-to-play?lang=th')
            ->assertOk()
            ->assertSee('ศูนย์ความรู้')
            ->assertSee('โปรดอ่านข้อจำกัด');

        $this->get('/how-to-play?lang=bn')
            ->assertOk()
            ->assertSee('শিক্ষা কেন্দ্র')
            ->assertSee('সমর্থিত পণ্য বেছে নিন')
            ->assertSee('সীমাগুলো পড়ুন');
    }

    public function test_services_alias_redirects_to_the_canonical_landing_page(): void
    {
        $this->get('/our-services')->assertRedirect(route('services'));
    }

    public function test_services_page_links_to_existing_core_public_routes(): void
    {
        $html = (string) $this->get('/services')->assertOk()->getContent();

        foreach (['/lotteries', '/results', '/check', '/lotto-discount', '/how-to-play', '/faq', '/fees', '/terms', '/account-verification-guide', '/account-grades', '/prize-verification', '/contact'] as $path) {
            $this->assertStringContainsString('href="'.url($path).'"', $html, 'Missing link to '.$path);
        }
    }
}
