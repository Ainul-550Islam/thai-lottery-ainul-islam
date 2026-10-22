<?php

declare(strict_types=1);

namespace Tests\Feature\Vision;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class VisionPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_vision_page_is_public_and_uses_the_canonical_route(): void
    {
        $response = $this->get(route('vision'));

        $response->assertOk();
        $response->assertSee('data-vision-page', false);
        $response->assertSee('data-pp-section="vision-mission"', false);
        $response->assertSee('data-pp-section="clear-values"', false);
        $response->assertSee('data-pp-section="governance"', false);
        $response->assertSee('data-pp-section="contact"', false);
        $response->assertSee('data-clear-value="collaboration"', false);
        $response->assertSee('data-clear-value="relationship"', false);
    }

    public function test_vision_page_has_real_ctas_and_absolute_canonical_metadata(): void
    {
        $content = (string) $this->get(route('vision'))->assertOk()->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="'.config('app.url').'/vision"', $content);
        $this->assertStringContainsString(route('about'), $content);
        $this->assertStringContainsString(route('results.index'), $content);
        $this->assertStringContainsString(route('contact'), $content);
        $this->assertStringNotContainsString('href="#"', $content);
        $this->assertStringNotContainsString('https://api.', $content);
    }
}
