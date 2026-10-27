<?php

declare(strict_types=1);

namespace Tests\Feature\Locale;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

final class BanglaPublicPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_auth_privacy_and_download_pages_render_bangla_copy(): void
    {
        $this->get('/login?lang=bn')
            ->assertOk()
            ->assertSee('সদস্য লগইন')
            ->assertSee('অ্যাকাউন্ট আইডি বা ইমেইল ঠিকানা')
            ->assertSee('পাসওয়ার্ড ভুলে গেছেন?');

        $this->get('/register?lang=bn')
            ->assertOk()
            ->assertSee('সদস্য নিবন্ধন')
            ->assertSee('রেফারেল আইডি')
            ->assertSee('পুরুষ')
            ->assertSee('নারী')
            ->assertSee('জন্মতারিখ')
            ->assertSee('ব্যবহারের শর্তাবলি');

        $this->get('/forgot-password?lang=bn')
            ->assertOk()
            ->assertSee('পাসওয়ার্ড ভুলে গেছেন?')
            ->assertSee('পুনরুদ্ধারের নির্দেশনা পাঠাব');

        $this->get('/privacy?lang=bn')
            ->assertOk()
            ->assertSee('গোপনীয়তা নীতি')
            ->assertSee('তথ্য সুরক্ষা')
            ->assertSee('গোপনীয়তা নীতির মূল পাঠ বর্তমানে ইংরেজিতে দেখানো হচ্ছে');

        $this->get('/terms?lang=bn')
            ->assertOk()
            ->assertSee('শর্তাবলির মূল পাঠ বর্তমানে ইংরেজিতে দেখানো হচ্ছে');

        $this->get('/download?lang=bn')
            ->assertOk()
            ->assertSee('অ্যাপ ডাউনলোড')
            ->assertSee('যাচাইকৃত HTTPS গন্তব্য');
    }

    public function test_bangla_terms_and_privacy_pages_disclose_english_legal_text(): void
    {
        $this->get('/terms?lang=bn')
            ->assertOk()
            ->assertSee('শর্তাবলির মূল পাঠ বর্তমানে ইংরেজিতে দেখানো হচ্ছে');

        $this->get('/privacy?lang=bn')
            ->assertOk()
            ->assertSee('গোপনীয়তা নীতির মূল পাঠ বর্তমানে ইংরেজিতে দেখানো হচ্ছে');
    }

    public function test_every_static_public_pages_reference_has_a_bangla_override(): void
    {
        $keys = [];

        foreach ([app_path(), resource_path('views')] as $root) {
            foreach (File::allFiles($root) as $file) {
                $contents = File::get($file->getPathname());
                preg_match_all(
                    '/(?:trans|__|@lang)\(\s*[\'\"]public_pages\.([A-Za-z0-9_.]+)[\'\"]/',
                    $contents,
                    $matches,
                );

                foreach ($matches[1] ?? [] as $key) {
                    if ($key === 'not_configured' || str_ends_with($key, '_') || $key === 'about_timeline.') {
                        continue;
                    }

                    $keys[$key] = true;
                }
            }
        }

        $this->assertNotEmpty($keys, 'The static public_pages reference scan must find translation keys.');

        foreach (array_keys($keys) as $key) {
            $this->assertTrue(Lang::has('public_pages.'.$key, 'en'), 'Missing English key: '.$key);
            $this->assertTrue(Lang::has('public_pages.'.$key, 'bn'), 'Missing Bangla key: '.$key);
            $this->assertNotSame(
                trans('public_pages.'.$key, [], 'en'),
                trans('public_pages.'.$key, [], 'bn'),
                'Bangla must not silently inherit English for public_pages.'.$key,
            );
        }
    }
}
