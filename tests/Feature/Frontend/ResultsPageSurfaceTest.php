<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend;

use Tests\TestCase;

/**
 * The public results page, as a visitor receives it.
 *
 * WHY THIS IS A SEPARATE SUITE FROM THE API TEST
 * ---------------------------------------------------------------------------
 * The endpoint test proves the server answers correctly. This proves the PAGE
 * ships the thing that calls it — the form, the result panel and the dictionary
 * the script reads. A page that renders without them is a page whose checker is
 * silently absent, and no API test would notice.
 *
 * It also pins the two rules that keep the page honest:
 *
 *   - every string the panel can show comes from a translation key, so the page
 *     has no English sentence hard-coded in the markup;
 *   - the endpoint in the markup is generated from the route NAME, so a route
 *     rename cannot leave a dead URL behind.
 */
final class ResultsPageSurfaceTest extends TestCase
{
    public function test_the_results_page_renders_the_prize_checker(): void
    {
        $response = $this->get('/results');

        $response->assertOk();
        $response->assertSee('data-prize-checker', false);
        $response->assertSee('data-prize-form', false);
        $response->assertSee('data-prize-input', false);
        $response->assertSee('data-prize-submit', false);
        $response->assertSee('data-prize-result', false);
        $response->assertSee('prize-checker-l10n', false);
    }

    public function test_the_checker_endpoint_in_the_markup_is_the_registered_route(): void
    {
        $response = $this->get('/results');

        $response->assertOk();
        $response->assertSee(e(route('api.v1.glo.prize-check')), false);
    }

    public function test_the_dictionary_block_is_valid_json_and_has_no_raw_markup(): void
    {
        $html = (string) $this->get('/results')->assertOk()->getContent();

        $this->assertSame(1, preg_match('~<script type="application/json" id="prize-checker-l10n">(.*?)</script>~s', $html, $matches));

        $decoded = json_decode(trim($matches[1]), true);

        $this->assertIsArray($decoded, 'the checker dictionary must be valid JSON');
        $this->assertArrayHasKey('invalid', $decoded);
        $this->assertArrayHasKey('not_published', $decoded);
        $this->assertArrayHasKey('endpoint', $decoded);

        // JSON_HEX_TAG means a translated string can never close the script tag
        // or open an element, even if a translator pastes markup into it.
        $this->assertStringNotContainsString('<', $matches[1]);
    }

    public function test_the_money_fields_start_empty(): void
    {
        $html = (string) $this->get('/results')->assertOk()->getContent();

        // Every money field is rendered as an em-dash placeholder and filled only
        // by the script from a server response. A figure present in the server
        // HTML would be shown to a visitor before anything was checked.
        foreach (['gross', 'stamp-duty', 'net'] as $field) {
            $this->assertSame(
                1,
                preg_match('~data-prize-'.$field.'>\s*([^<]*)<~u', $html, $matches),
                "the {$field} field must be present",
            );

            $value = trim($matches[1]);

            $this->assertSame('—', $value, "the {$field} field must start as a placeholder, not a figure");
            $this->assertSame(0, preg_match('/\d/', $value), "the {$field} field must contain no digits before a check");
        }
    }
}
