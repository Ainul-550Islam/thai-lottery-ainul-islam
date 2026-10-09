<?php

declare(strict_types=1);

namespace Tests\Feature\About;

use App\Services\PublicPages\AboutPageService;
use Tests\TestCase;

/**
 * Public content assertions are read-only; no migration reset or data fixture is needed.
 */
final class AboutContentIntegrityTest extends TestCase
{
    public function test_timeline_is_ordered_and_source_labelled(): void
    {
        $content = (string) $this->get(route('about'))->assertOk()->getContent();

        $this->assertStringContainsString('HISTORICAL REFERENCE', $content);
        $this->assertStringContainsString('SOURCE-LABELLED HISTORY', $content);
        // AboutTimelineService publishes display_order 1..8 ascending by year,
        // so the timeline reads oldest first. This assertion previously
        // demanded 1939 before 1917, which no ordering of that data produces.
        $this->assertLessThan(
            strpos($content, '1939'),
            strpos($content, '1917'),
            'the timeline reads oldest first',
        );
    }

    public function test_about_page_does_not_render_unsupported_identity_or_unsafe_html(): void
    {
        $content = (string) $this->get(route('about'))->assertOk()->getContent();

        foreach (['official agent portal', 'government-operated website', 'licensed by', 'PAGCOR', 'MGA Malta Gaming Authority'] as $claim) {
            $this->assertStringNotContainsStringIgnoringCase($claim, $content);
        }

        $this->assertDoesNotMatchRegularExpression(
            '/<script(?![^>]*\\bsrc=)/i',
            $content,
            'The page must carry no inline script: content is rendered escaped, and an '
                .'inline block would also force a CSP to allow unsafe-inline sitewide.',
        );
        $this->assertStringNotContainsString('href="#"', $content);
    }

    public function test_about_page_has_a_safe_empty_state_contract(): void
    {
        $data = app(AboutPageService::class)->data('en');

        $this->assertArrayHasKey('timeline', $data);
        $this->assertArrayHasKey('empty_label', $data['timeline']);
        $this->assertIsArray($data['timeline']['items']);
    }
}
