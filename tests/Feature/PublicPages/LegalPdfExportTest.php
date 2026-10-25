<?php

declare(strict_types=1);

namespace Tests\Feature\PublicPages;

use App\DTOs\Legal\LegalPdfDocument;
use App\Enums\AuditAction;
use App\Exceptions\Legal\LegalPdfNotAvailableException;
use App\Models\AuditLog;
use App\Models\LegalDocument;
use App\Models\User;
use App\Services\Legal\LegalDocumentService;
use App\Services\PublicPages\LegalPdfExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The Terms/Fees downloads, both ways.
 *
 * OFF: the endpoints keep the exact 501 body they always answered (asserted
 * again here so a change to the lane can never quietly change the old
 * contract).
 *
 * ON: a real PDF of the PUBLISHED document, stamped with its revision and a
 * SHA-256 of the returned bytes. An unpublished document, a row whose stored
 * content hash no longer matches its content, and a deployment whose fonts are
 * missing all refuse — none of them degrades into a "PDF" that says nothing
 * was verified.
 */
final class LegalPdfExportTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGINAL_TERMS_BODY = [
        'success' => false,
        'state' => 'NOT_CONFIGURED',
        'message' => 'A verified Terms PDF export is not configured for this environment. Use the browser print or save control.',
    ];

    private const ORIGINAL_FEES_BODY = [
        'success' => false,
        'state' => 'NOT_CONFIGURED',
        'message' => 'A verified fee-schedule PDF export is not configured. Use the browser print or save control.',
    ];

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'legal_pdf.enabled' => false,
            'legal_pdf.cache_ttl_minutes' => 60,
            'legal_pdf.storage_disk' => 'local',
            'legal_pdf.verify_content_hash' => true,
        ]);

        Storage::fake('local');
        $this->actor = User::factory()->create();
    }

    private function enableLane(): void
    {
        config(['legal_pdf.enabled' => true]);
    }

    /**
     * Publish a real versioned document through the canonical service, so the
     * test exercises the same publication pointer the site reads.
     *
     * @param  array<int, array<string, mixed>>  $sections
     */
    private function publishDocument(
        string $type,
        string $locale,
        string $version = 'v9.9.9',
        ?array $sections = null,
    ): LegalDocument {
        $sections ??= [
            [
                'id' => 'scope',
                'title' => $type === 'terms' ? 'Scope and platform identity' : 'Data we process',
                'body' => 'This document is published for the export test. It is independent content, '
                    .'not the Government Lottery Office and not an official agent of it.',
                'extra' => ['A second paragraph so the renderer has a list to draw.'],
            ],
            [
                'id' => 'claims',
                'title' => 'Claims and payouts',
                'body' => 'Claims are subject to verification, eligibility review and applicable holds.',
            ],
        ];

        $draft = app(LegalDocumentService::class)->createDraft([
            'document_type' => $type,
            'locale' => $locale,
            'version' => $version,
            'title' => $type === 'terms' ? 'Terms of Use' : 'Privacy Notice',
            'meta_title' => $type === 'terms' ? 'Terms of Use' : 'Privacy Notice',
            'meta_description' => 'Published document used by the PDF export suite.',
            'sections' => $sections,
            'effective_at' => now()->subDay()->toDateString(),
        ], $this->actor);

        return app(LegalDocumentService::class)->publish((int) $draft->getKey(), $this->actor);
    }

    public function test_with_the_lane_off_both_downloads_keep_the_original_501_body(): void
    {
        $terms = $this->get('/api/v1/public/terms/download');
        $terms->assertStatus(501);
        $this->assertSame(json_encode(self::ORIGINAL_TERMS_BODY), $terms->getContent());

        $fees = $this->get('/api/v1/public/fees/download');
        $fees->assertStatus(501);
        $this->assertSame(json_encode(self::ORIGINAL_FEES_BODY), $fees->getContent());
    }

    public function test_the_published_terms_export_is_a_real_pdf_with_verification_headers(): void
    {
        $this->enableLane();
        $document = $this->publishDocument('terms', 'en');

        $response = $this->get('/api/v1/public/terms/download?locale=en');

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('filename="terms-en-', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame($document->version, $response->headers->get('X-Document-Version'));
        $this->assertSame('"'.hash('sha256', (string) $response->getContent()).'"', $response->headers->get('ETag'));
    }

    public function test_thai_and_bangla_exports_are_produced_in_their_own_language(): void
    {
        $this->enableLane();

        // Thai has a database-backed publication; Bangla's published copy is the
        // config-backed page the site actually serves for bn.
        $this->publishDocument('terms', 'th', 'v9.9.9-th');

        $thai = $this->get('/api/v1/public/terms/download?locale=th');
        $thai->assertOk();
        $this->assertStringStartsWith('%PDF-', (string) $thai->getContent());
        $this->assertSame('v9.9.9-th', $thai->headers->get('X-Document-Version'));

        $bangla = $this->get('/api/v1/public/terms/download?locale=bn');
        $bangla->assertOk();
        $this->assertStringStartsWith('%PDF-', (string) $bangla->getContent());
        $this->assertNotEmpty($bangla->headers->get('X-Document-Version'));

        $this->assertNotSame($thai->getContent(), $bangla->getContent());
    }

    public function test_an_unpublished_document_is_a_safe_404_never_a_substitute_pdf(): void
    {
        $this->enableLane();

        $response = $this->get('/api/v1/public/terms/download?locale=th');

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('state', 'NOT_AVAILABLE')
            ->assertJsonPath('code', LegalPdfNotAvailableException::CODE);

        $this->assertStringNotContainsString('%PDF', (string) $response->getContent());
        $this->assertStringNotContainsString('LegalDocumentService', (string) $response->getContent());
    }

    public function test_a_draft_revision_is_never_exported(): void
    {
        $this->enableLane();

        app(LegalDocumentService::class)->createDraft([
            'document_type' => 'terms',
            'locale' => 'en',
            'version' => 'v-draft-only',
            'title' => 'Draft Terms',
            'meta_title' => 'Draft Terms',
            'meta_description' => 'A draft that was never published.',
            'sections' => [['id' => 'a', 'title' => 'A', 'body' => 'Draft body.']],
            'effective_at' => now()->subDay()->toDateString(),
        ], $this->actor);

        $this->get('/api/v1/public/terms/download?locale=en')->assertStatus(404);
    }

    public function test_a_tampered_content_hash_refuses_the_export_and_is_audited(): void
    {
        $this->enableLane();
        $document = $this->publishDocument('terms', 'en');

        // Edit the row behind the workflow's back: content changes, the stored
        // hash does not. Exporting this would stamp "verified" on text nobody
        // verified, so it must refuse and leave a security audit row.
        DB::table('legal_documents')->where('id', (int) $document->getKey())->update([
            'sections' => json_encode([
                ['id' => 'injected', 'title' => 'Injected', 'body' => 'Text that was never published.'],
            ], JSON_THROW_ON_ERROR),
        ]);

        $response = $this->get('/api/v1/public/terms/download?locale=en');

        $response->assertStatus(404)->assertJsonPath('code', LegalPdfNotAvailableException::CODE);
        $this->assertStringNotContainsString('%PDF', (string) $response->getContent());

        $audit = AuditLog::query()
            ->where('action', AuditAction::SecurityAlert->value)
            ->latest('id')
            ->first();

        $this->assertNotNull($audit, 'A content-hash mismatch must leave an audit trail.');
        $this->assertSame('terms', (string) ($audit->metadata['document_type'] ?? ''));
        $this->assertSame('en', (string) ($audit->metadata['locale'] ?? ''));
        $this->assertSame(
            LegalPdfNotAvailableException::REASON_HASH_MISMATCH,
            (string) ($audit->metadata['reason'] ?? ''),
        );
    }

    public function test_the_second_call_is_served_from_the_private_cache(): void
    {
        $this->enableLane();
        $this->publishDocument('terms', 'en');

        $first = $this->get('/api/v1/public/terms/download?locale=en');
        $first->assertOk();

        $contentHash = (string) app(LegalDocumentService::class)->currentData('terms', 'en')['content_hash'];
        $cacheKey = app(LegalPdfExportService::class)->cacheKey('terms', 'en', 'v9.9.9', $contentHash);

        $this->assertTrue(Cache::has($cacheKey), 'The render must be recorded under its revision key.');
        $this->assertNotSame([], Storage::disk('local')->allFiles('legal-pdf'), 'The render must be cached on the private disk.');

        $second = $this->get('/api/v1/public/terms/download?locale=en');
        $second->assertOk();

        $this->assertSame($first->getContent(), $second->getContent());
        $this->assertSame($first->headers->get('ETag'), $second->headers->get('ETag'));
    }

    public function test_a_matching_if_none_match_gets_a_304_without_a_body(): void
    {
        $this->enableLane();
        $this->publishDocument('terms', 'en');

        $etag = (string) $this->get('/api/v1/public/terms/download?locale=en')->headers->get('ETag');
        $this->assertNotSame('', $etag);

        $response = $this->withHeaders(['If-None-Match' => $etag])
            ->get('/api/v1/public/terms/download?locale=en');

        $response->assertStatus(304);
        $this->assertSame('', (string) $response->getContent());
    }

    public function test_an_unsupported_download_locale_is_refused(): void
    {
        $this->enableLane();

        $this->get('/api/v1/public/terms/download?locale=fr')
            ->assertStatus(422)
            ->assertJsonPath('state', 'UNSUPPORTED_LOCALE')
            ->assertJsonPath('supported_locales', ['en', 'th', 'bn']);
    }

    public function test_the_fee_schedule_export_streams_the_published_schedule(): void
    {
        $this->enableLane();

        $response = $this->get('/api/v1/public/fees/download?locale=en');

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());
        $this->assertSame((string) config('fees.rule_version', '1'), $response->headers->get('X-Document-Version'));
    }

    public function test_the_service_exposes_the_privacy_export_contract(): void
    {
        config(['legal_pdf.enabled' => true]);
        $this->publishDocument('privacy', 'en', 'v-privacy-1');

        $pdf = app(LegalPdfExportService::class)->export('privacy', 'en');

        $this->assertInstanceOf(LegalPdfDocument::class, $pdf);
        $this->assertTrue($pdf->isPdf());
        $this->assertSame(hash('sha256', $pdf->contents), $pdf->sha256);
        $this->assertSame('v-privacy-1', $pdf->version);
        $this->assertStringEndsWith('.pdf', $pdf->filename);
        $this->assertSame($pdf->sha256, substr($pdf->etag(), 1, 64));
        $this->assertSame(substr($pdf->sha256, 0, 16), $pdf->shortHash(16));
    }

    public function test_the_service_refuses_a_type_it_does_not_render(): void
    {
        config(['legal_pdf.enabled' => true]);

        try {
            app(LegalPdfExportService::class)->export('marketing', 'en');
            $this->fail('An unsupported document type must be refused.');
        } catch (LegalPdfNotAvailableException $exception) {
            $this->assertSame(LegalPdfNotAvailableException::CODE, $exception->errorCode());
            $this->assertSame(LegalPdfNotAvailableException::REASON_UNSUPPORTED_TYPE, $exception->reason());
        }
    }

    public function test_the_service_refuses_to_render_when_its_fonts_are_missing(): void
    {
        config([
            'legal_pdf.enabled' => true,
            'legal_pdf.font_dir' => storage_path('app/private/does-not-exist'),
        ]);

        $this->publishDocument('terms', 'en');

        // ON but misconfigured fails closed with a safe error; it never renders
        // with a substitute font and calls the result a verified export.
        $response = $this->get('/api/v1/public/terms/download?locale=en');
        $response->assertStatus(404);
        $this->assertStringNotContainsString('%PDF', (string) $response->getContent());
    }

    public function test_the_rendered_pdf_carries_the_published_revision_in_its_metadata(): void
    {
        $this->enableLane();
        $this->publishDocument('terms', 'en', 'v9.9.9');

        $pdf = app(LegalPdfExportService::class)->export('terms', 'en');

        $this->assertTrue($pdf->isPdf());
        $this->assertGreaterThan(2000, $pdf->length(), 'A rendered legal document is not an empty page.');
        $this->assertSame('v9.9.9', $pdf->version);
        $this->assertStringContainsString('/Title', $pdf->contents);
    }
}
