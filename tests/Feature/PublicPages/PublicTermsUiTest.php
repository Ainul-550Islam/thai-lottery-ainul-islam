<?php

declare(strict_types=1);

namespace Tests\Feature\PublicPages;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PublicTermsUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_terms_uses_the_canonical_source_driven_ui_contract(): void
    {
        $html = (string) $this->get('/terms')->assertOk()->getContent();

        $this->assertStringContainsString('data-terms-page', $html);
        $this->assertStringContainsString('data-terms-contents', $html);
        $this->assertStringContainsString('data-terms-search', $html);
        $this->assertStringContainsString('data-print-terms', $html);
        $this->assertStringContainsString('id="scope"', $html);
        $this->assertStringContainsString('href="#scope"', $html);
        $this->assertStringContainsString('PRINT / SAVE', $html);
        $this->assertStringContainsString('window.print', (string) file_get_contents(resource_path('js/pages/terms.js')));
    }

    public function test_terms_has_no_fake_acceptance_or_download_controls(): void
    {
        $html = (string) $this->get('/terms')->assertOk()->getContent();

        $this->assertStringNotContainsString('accept-terms', $html);
        $this->assertStringNotContainsString('download-pdf', $html);
        $this->assertStringNotContainsString('accepted_at', $html);
        $this->assertStringNotContainsString('cryptographic', $html);
        $this->assertStringNotContainsString('innerHTML', (string) file_get_contents(resource_path('js/pages/terms.js')));
        $this->assertStringNotContainsString('{!!', (string) file_get_contents(resource_path('views/terms/index.blade.php')));
    }

    public function test_terms_api_reuses_canonical_source_and_does_not_invent_export_or_consent(): void
    {
        $this->getJson('/api/v1/public/terms')
            ->assertOk()
            ->assertJsonPath('data.version', config('legal.version'))
            ->assertJsonPath('data.sections.0.id', 'scope');

        $this->postJson('/api/v1/public/terms/accept')
            ->assertStatus(501)
            ->assertJsonPath('state', 'NOT_CONFIGURED');

        $this->getJson('/api/v1/public/terms/download')
            ->assertStatus(501)
            ->assertJsonPath('state', 'NOT_CONFIGURED');
    }

    public function test_terms_legacy_php_route_redirects_to_canonical_terms(): void
    {
        $this->get('/terms.php')
            ->assertRedirect(route('terms'));
    }

    public function test_terms_has_english_and_thai_source_parity_without_raw_keys(): void
    {
        $english = (array) require base_path('lang/en/public_pages.php');
        $thai = (array) require base_path('lang/th/public_pages.php');
        $this->assertSame(array_keys($english), array_keys($thai));

        app()->setLocale('th');
        $thaiHtml = (string) $this->get('/terms')->assertOk()->getContent();
        $this->assertStringContainsString('ข้อกำหนดการใช้งาน', $thaiHtml);
        $this->assertStringNotContainsString('public_pages.terms_', $thaiHtml);
    }
}
