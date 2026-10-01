<?php

declare(strict_types=1);

namespace Tests\Feature\About;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AboutContentIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_timeline_is_ordered_and_source_labelled(): void
    {
        $content = (string) $this->get(route('about'))->assertOk()->getContent();

        $this->assertStringContainsString('HISTORICAL REFERENCE', $content);
        $this->assertStringContainsString('SOURCE-LABELLED HISTORY', $content);
        $this->assertLessThan(
            strpos($content, '1917'),
            strpos($content, '1939'),
        );
    }

    public function test_about_page_does_not_render_unsupported_identity_or_unsafe_html(): void
    {
        $content = (string) $this->get(route('about'))->assertOk()->getContent();

        foreach (['official agent portal', 'government-operated website', 'licensed by', 'PAGCOR', 'MGA Malta Gaming Authority'] as $claim) {
            $this->assertStringNotContainsStringIgnoringCase($claim, $content);
        }

        $this->assertStringNotContainsString('<script', strtolower($content));
        $this->assertStringNotContainsString('href="#"', $content);
    }

    public function test_about_page_has_a_safe_empty_state_contract(): void
    {
        $data = app(\App\Services\PublicPages\AboutPageService::class)->data('en');

        $this->assertArrayHasKey('timeline', $data);
        $this->assertArrayHasKey('empty_label', $data['timeline']);
        $this->assertIsArray($data['timeline']['items']);
    }
}
