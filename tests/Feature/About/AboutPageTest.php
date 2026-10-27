<?php

declare(strict_types=1);

namespace Tests\Feature\About;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class AboutPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_about_page_is_public_and_uses_the_canonical_route(): void
    {
        $response = $this->get(route('about'));

        $response->assertOk();
        $response->assertSee('data-about-page', false);
        $response->assertSee('data-pp-step="choose"', false);
        $response->assertSee('data-pp-step="buy"', false);
        $response->assertSee('data-pp-step="result"', false);
        $response->assertSee('data-pp-step="claim"', false);
        $response->assertSee('id="about-timeline"', false);
        $response->assertSee('data-pp-section="contact"', false);
    }

    public function test_about_page_contains_absolute_seo_metadata_and_real_ctas(): void
    {
        $content = (string) $this->get(route('about'))->assertOk()->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="'.config('app.url').'/about"', $content);
        $this->assertStringContainsString(route('results.index'), $content);
        $this->assertStringContainsString(route('ticket-check'), $content);
        $this->assertStringContainsString(route('contact'), $content);
        $this->assertStringNotContainsString('href="#"', $content);
        $this->assertStringNotContainsString('https://api.', $content);
    }

    public function test_about_page_is_not_a_government_identity_claim(): void
    {
        $content = (string) $this->get(route('about'))->assertOk()->getContent();

        $this->assertStringContainsString('not the Government Lottery Office', $content);
        $this->assertStringContainsString('not an official GLO agent', $content);
        $this->assertStringContainsString('not a government portal', $content);
        $this->assertStringNotContainsString('PAGCOR', $content);
        $this->assertStringNotContainsString('ISO 27001', $content);
        $this->assertStringNotContainsString('250,000+', $content);
    }
}
