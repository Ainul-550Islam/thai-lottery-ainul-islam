<?php

declare(strict_types=1);

namespace App\Services\PublicPages;

use App\DTOs\Legal\LegalPdfDocument;
use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Exceptions\Legal\LegalPdfNotAvailableException;
use App\Services\Audit\AuditLogService;
use App\Services\Legal\LegalDocumentService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Throwable;

/**
 * Versioned, hash-stamped PDF export of a PUBLISHED legal document.
 *
 * =========================================================================
 * WHAT THIS SERVICE PROMISES
 * =========================================================================
 * export($type, $locale) returns App\DTOs\Legal\LegalPdfDocument — the bytes,
 * the file name, the SHA-256 of those exact bytes and the document revision —
 * or it throws App\Exceptions\Legal\LegalPdfNotAvailableException. It never
 * returns a PDF it could not verify, and it never renders a document that is
 * not published.
 *
 * =========================================================================
 * PUBLICATION IS THE GATE, AND THE HASH IS THE SEAL
 * =========================================================================
 *  - Terms and Privacy are read through App\Services\Legal\LegalDocumentService,
 *    which returns status AVAILABLE only when a PUBLISHED revision is the
 *    current publication for that (type, locale). A draft, a superseded
 *    revision, a future effective date or no publication at all therefore
 *    refuses rather than exporting whatever text happens to be in config.
 *  - Bangla has no database-backed legal document (the legal document store is
 *    defined for en/th); its published copy IS the config-backed Bangla page the
 *    site actually serves, and that page carries its own computed content hash,
 *    so it is verified by exactly the same rule as a published row.
 *  - Fees are not a LegalDocument: the published fee schedule is the projection
 *    App\Services\PublicPages\FeesPageService builds from config/fees.php. An
 *    empty projection refuses.
 *  - Before rendering, the stored content_hash is compared with a hash
 *    recomputed from the content that is about to be printed. A row edited
 *    behind the workflow's back (a direct UPDATE that leaves the hash stale)
 *    refuses, and the refusal is audited as a security event. Stamping
 *    "verified v1.0.1" on bytes nobody verified would be the exact failure this
 *    check exists to prevent.
 *
 * =========================================================================
 * CACHING
 * =========================================================================
 * A render is cached on the PRIVATE disk under
 * (type, locale, version, content_hash) with a bounded TTL, and the cache entry
 * is recorded in the application cache. A new publication changes the version
 * and/or the hash, so it is a miss — a stale export can never outlive the
 * document it describes inside the TTL. A cache entry whose file has vanished
 * is treated as a miss and re-rendered, never served as a broken attachment.
 *
 * =========================================================================
 * OUTPUT SAFETY
 * =========================================================================
 * Everything the template prints is escaped. No external asset is fetched: the
 * fonts are embedded from resources/fonts, so a render works with the network
 * down and cannot be influenced by a remote stylesheet.
 */
final class LegalPdfExportService
{
    public const TYPE_TERMS = 'terms';

    public const TYPE_PRIVACY = 'privacy';

    public const TYPE_FEES = 'fees';

    /** Locales the legal document store itself is defined for. */
    private const STORE_LOCALES = ['en', 'th'];

    public function __construct(
        private readonly LegalDocumentService $legalDocuments,
        private readonly FeesPageService $fees,
        private readonly PublicPageTextBag $text,
        private readonly AuditLogService $audit,
    ) {
    }

    /**
     * Render (or reuse) the verified PDF for a published document.
     *
     * @throws LegalPdfNotAvailableException
     */
    public function export(string $type, string $locale): LegalPdfDocument
    {
        if (! $this->enabled()) {
            throw LegalPdfNotAvailableException::disabled();
        }

        $type = strtolower(trim($type));
        $locale = strtolower(trim($locale));

        if (! in_array($type, $this->supportedTypes(), true)) {
            throw LegalPdfNotAvailableException::unsupportedType($type);
        }

        if (! in_array($locale, $this->supportedLocales(), true)) {
            throw LegalPdfNotAvailableException::unsupportedLocale($locale);
        }

        $document = $type === self::TYPE_FEES
            ? $this->feeSchedule($locale)
            : $this->legalDocument($type, $locale);

        $cached = $this->fromCache($type, $locale, $document);

        if ($cached instanceof LegalPdfDocument) {
            return $cached;
        }

        $contents = $this->render($type, $locale, $document);

        $pdf = new LegalPdfDocument(
            contents: $contents,
            filename: $this->filename($type, $locale, $document),
            sha256: hash('sha256', $contents),
            version: (string) $document['version'],
        );

        $this->remember($type, $locale, $document, $pdf);

        return $pdf;
    }

    /**
     * The cache key a render for this exact revision is stored under.
     *
     * Public so an operator (or a test) can prove which revision a cached file
     * belongs to without rendering it again.
     */
    public function cacheKey(string $type, string $locale, string $version, string $contentHash): string
    {
        return sprintf(
            'legal_pdf.%s.%s.v_%s.h_%s',
            strtolower($type),
            strtolower($locale),
            md5($version),
            md5($contentHash),
        );
    }

    public function enabled(): bool
    {
        return (bool) config('legal_pdf.enabled', false);
    }

    /**
     * @return list<string>
     */
    public function supportedTypes(): array
    {
        $types = array_values(array_filter(
            array_map('strval', (array) config('legal_pdf.supported_types', [])),
            static fn (string $type): bool => $type !== '',
        ));

        return $types === [] ? [self::TYPE_TERMS, self::TYPE_PRIVACY, self::TYPE_FEES] : $types;
    }

    /**
     * @return list<string>
     */
    public function supportedLocales(): array
    {
        $locales = array_values(array_filter(
            array_map('strval', (array) config('legal_pdf.supported_locales', [])),
            static fn (string $locale): bool => $locale !== '',
        ));

        return $locales === [] ? ['en', 'th', 'bn'] : $locales;
    }

    /**
     * Canonical content hash — the same algorithm LegalDocumentService stamps on
     * a draft, so a stored hash can be re-verified from the content alone.
     *
     * @param  array<string, mixed>  $content
     */
    public function contentHash(array $content): string
    {
        $canonical = $this->sortRecursively([
            'title' => (string) ($content['title'] ?? ''),
            'meta_title' => (string) ($content['meta_title'] ?? ''),
            'meta_description' => (string) ($content['meta_description'] ?? ''),
            'sections' => is_array($content['sections'] ?? null) ? array_values($content['sections']) : [],
        ]);

        return hash('sha256', json_encode(
            $canonical,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * Resolve a published Terms/Privacy revision and verify its seal.
     *
     * @return array{
     *     type: string,
     *     locale: string,
     *     title: string,
     *     version: string,
     *     effective_at: string,
     *     sections: array<int, array<string, mixed>>,
     *     content_hash: string,
     *     legal_document_id: int|null
     * }
     *
     * @throws LegalPdfNotAvailableException
     */
    private function legalDocument(string $type, string $locale): array
    {
        $data = $this->legalDocuments->currentData($type, $locale);

        $sections = is_array($data['sections'] ?? null) ? array_values($data['sections']) : [];
        $publishedId = isset($data['legal_document_id']) ? (int) $data['legal_document_id'] : null;
        $storeBacked = in_array($locale, self::STORE_LOCALES, true);

        // 'status' => AVAILABLE only means "this payload can be rendered"; the
        // config-backed copy answers AVAILABLE too. What makes a document
        // PUBLISHED for en/th is that the payload points at a real publication
        // row, and that is the distinction that decides an export. Bangla has
        // no row-backed document by design, so its config-backed page is the
        // published copy.
        if ($sections === [] || ($storeBacked && $publishedId === null)) {
            throw LegalPdfNotAvailableException::notPublished($type, $locale);
        }

        $version = (string) ($data['version'] ?? '');
        $storedHash = (string) ($data['content_hash'] ?? '');
        $computedHash = $this->contentHash($data);

        if ((bool) config('legal_pdf.verify_content_hash', true)) {
            if ($storedHash === '' || ! hash_equals($storedHash, $computedHash)) {
                $this->auditHashMismatch($type, $locale, $version, $data);

                throw LegalPdfNotAvailableException::hashMismatch($type, $locale, $version);
            }
        }

        return [
            'type' => $type,
            'locale' => $locale,
            'title' => (string) ($data['title'] ?? ''),
            'version' => $version,
            'effective_at' => (string) ($data['effective_at'] ?? ''),
            'sections' => $sections,
            'content_hash' => $computedHash,
            'legal_document_id' => $publishedId,
        ];
    }

    /**
     * Resolve the published fee schedule.
     *
     * @return array{
     *     type: string,
     *     locale: string,
     *     title: string,
     *     version: string,
     *     effective_at: string,
     *     sections: array<int, array<string, mixed>>,
     *     fee_groups: array<int, array<string, mixed>>,
     *     currency: string,
     *     content_hash: string,
     *     legal_document_id: null
     * }
     *
     * @throws LegalPdfNotAvailableException
     */
    private function feeSchedule(string $locale): array
    {
        $groups = $this->fees->publicFeeGroups($locale);
        $rows = $this->fees->publicFees($locale);

        if ($groups === [] || $rows === []) {
            throw LegalPdfNotAvailableException::notPublished(self::TYPE_FEES, $locale);
        }

        $version = (string) config('fees.rule_version', '1');
        $payload = [
            'title' => $this->text->line('fees_title', $locale),
            'meta_title' => $this->text->line('fees_meta_title', $locale),
            'meta_description' => $this->text->line('fees_meta_description', $locale),
            'sections' => [],
        ];

        return [
            'type' => self::TYPE_FEES,
            'locale' => $locale,
            'title' => $payload['title'] !== '' ? $payload['title'] : (string) trans('public_pages.fees_title', [], $locale),
            'version' => $version,
            'effective_at' => (string) config('legal.effective_at', ''),
            'sections' => [],
            'fee_groups' => $groups,
            'currency' => (string) config('fees.currency', 'THB'),
            // The seal of a config-projected schedule is its own projection: the
            // same rule version rendering the same rows produces the same hash.
            'content_hash' => hash('sha256', json_encode(
                ['rule_version' => $version, 'rows' => $rows],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            )),
            'legal_document_id' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     *
     * @throws LegalPdfNotAvailableException
     */
    private function render(string $type, string $locale, array $document): string
    {
        $fontFamily = $this->fontFamily($locale);
        $this->assertFontsInstalled();

        $generatedAt = now()->utc();
        $footerHash = substr((string) $document['content_hash'], 0, max(0, (int) config('legal_pdf.footer_hash_chars', 16)));

        $html = view('pdf.legal-document', [
            'type' => $type,
            'locale' => $locale,
            'localeLabel' => (string) trans('public_pages.pdf_locale_label', [], $locale),
            'documentTitle' => (string) ($document['title'] ?? ''),
            'version' => (string) $document['version'],
            'effectiveAt' => (string) ($document['effective_at'] ?? ''),
            'generatedAt' => $generatedAt->format('Y-m-d H:i'),
            'contentHashShort' => $footerHash,
            'sections' => (array) ($document['sections'] ?? []),
            'feeGroups' => (array) ($document['fee_groups'] ?? []),
            'currency' => (string) ($document['currency'] ?? ''),
            'operatorName' => $this->operatorName(),
            'fontFamily' => $fontFamily,
            'baseFontSize' => max(8, min(14, (int) config('legal_pdf.paper.base_font_size_pt', 10))),
            'versionLabel' => (string) trans('public_pages.pdf_version_label', [], $locale),
            'effectiveLabel' => (string) trans('public_pages.pdf_effective_label', [], $locale),
            'generatedLabel' => (string) trans('public_pages.pdf_generated_label', [], $locale),
            'feesCategoryLabel' => (string) trans('public_pages.pdf_fees_category_label', [], $locale),
            'feesProviderLabel' => (string) trans('public_pages.pdf_fees_provider_label', [], $locale),
            'feesAmountLabel' => (string) trans('public_pages.pdf_fees_amount_label', [], $locale),
            'feesNotesLabel' => (string) trans('public_pages.pdf_fees_notes_label', [], $locale),
            'feesNotConfiguredLabel' => (string) trans('public_pages.pdf_fees_not_configured_label', [], $locale),
        ])->render();

        $footer = $this->footerHtml($locale, $fontFamily, (string) $document['version'], $footerHash, $generatedAt->format('Y-m-d H:i'));

        try {
            $mpdf = new Mpdf($this->mpdfConfig($fontFamily));

            $mpdf->SetTitle($this->pdfTitle($type, $locale, $document));
            $mpdf->SetAuthor($this->operatorName());
            $mpdf->SetCreator((string) config('legal_pdf.metadata.creator', 'Thai Lottery public legal documents'));
            $mpdf->SetSubject(sprintf(
                '%s · %s · %s',
                (string) config('legal_pdf.metadata.subject_prefix', 'Legal document'),
                (string) $document['type'],
                (string) $document['version'],
            ));

            $mpdf->SetHTMLFooter($footer);
            $mpdf->WriteHTML($html);

            $contents = $mpdf->Output('', 'S');
        } catch (LegalPdfNotAvailableException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw LegalPdfNotAvailableException::renderFailed($exception::class);
        }

        if (! is_string($contents) || ! str_starts_with($contents, '%PDF-')) {
            throw LegalPdfNotAvailableException::renderFailed('the renderer returned no PDF bytes');
        }

        return $contents;
    }

    /**
     * The running footer printed on every page.
     *
     * It names the revision, a hash fragment of the content seal, when the file
     * was generated and the page number, so a printed copy can be matched to a
     * published revision without trusting the printed body. Every operator
     * value is escaped; {PAGENO}/{nbpg} are mPDF placeholders and are emitted
     * literally on purpose.
     */
    private function footerHtml(
        string $locale,
        string $fontFamily,
        string $version,
        string $hashFragment,
        string $generatedAt,
    ): string {
        $versionLabel = (string) trans((string) config('legal_pdf.footer.version_key', 'public_pages.pdf_footer_version'), [], $locale);
        $hashLabel = (string) trans((string) config('legal_pdf.footer.hash_key', 'public_pages.pdf_footer_hash'), [], $locale);
        $generatedLabel = (string) trans((string) config('legal_pdf.footer.generated_key', 'public_pages.pdf_footer_generated'), [], $locale);
        $pageLabel = (string) trans((string) config('legal_pdf.footer.page_key', 'public_pages.pdf_footer_page'), [], $locale);

        return '<div style="font-family:'.e($fontFamily).';font-size:7.5pt;color:#5b6779;'
            .'border-top:0.2mm solid #c9d1dc;padding-top:2mm;text-align:center;">'
            .e($versionLabel).' '.e($version)
            .' &middot; '.e($hashLabel).' '.e($hashFragment)
            .' &middot; '.e($generatedLabel).' '.e($generatedAt).' UTC'
            .' &middot; '.e($pageLabel).' {PAGENO}/{nbpg}'
            .'</div>';
    }

    /**
     * @return array<string, mixed>
     */
    private function mpdfConfig(string $fontFamily): array
    {
        $defaultConfig = (new ConfigVariables)->getDefaults();
        $defaultFontConfig = (new FontVariables)->getDefaults();

        $fontDir = rtrim((string) config('legal_pdf.font_dir', resource_path('fonts')), '/');
        $fontData = $defaultFontConfig['fontdata'];
        $fontDirectories = $defaultConfig['fontDir'];

        foreach ((array) config('legal_pdf.fonts', []) as $family => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $folder = (string) ($definition['folder'] ?? '');
            $files = (array) ($definition['files'] ?? []);

            $fontData[(string) $family] = $files + [
                'useOTL' => (int) ($definition['use_otl'] ?? 0),
                'useKashida' => (int) ($definition['use_kashida'] ?? 0),
            ];

            if ($folder !== '') {
                $fontDirectories[] = $fontDir.'/'.$folder;
            }
        }

        $paper = (array) config('legal_pdf.paper', []);
        $tempPath = $this->tempPath();

        return [
            'mode' => 'utf-8',
            'format' => (string) ($paper['size'] ?? 'A4'),
            'orientation' => (string) ($paper['orientation'] ?? 'P'),
            'margin_top' => (int) ($paper['margin_top'] ?? 18),
            'margin_bottom' => (int) ($paper['margin_bottom'] ?? 22),
            'margin_left' => (int) ($paper['margin_left'] ?? 16),
            'margin_right' => (int) ($paper['margin_right'] ?? 16),
            'margin_header' => (int) ($paper['margin_header'] ?? 8),
            'margin_footer' => (int) ($paper['margin_footer'] ?? 10),
            'tempDir' => $tempPath,
            'fontDir' => array_values(array_unique($fontDirectories)),
            'fontdata' => $fontData,
            'default_font' => $fontFamily,
            'useKerning' => (bool) config('legal_pdf.use_kerning', true),
            // The font is chosen per document locale, so mPDF must not re-route
            // runs to its own lang→font table: doing so re-splits Bangla text
            // into chunks that lose their conjunct clusters.
            'autoScriptToLang' => false,
            'autoVietnamese' => false,
            'autoArabic' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function pdfTitle(string $type, string $locale, array $document): string
    {
        $title = trim((string) ($document['title'] ?? ''));

        if ($title === '') {
            $title = ucfirst($type);
        }

        return sprintf('%s (%s) %s', $title, strtoupper($locale), (string) $document['version']);
    }

    private function fontFamily(string $locale): string
    {
        $map = (array) config('legal_pdf.locale_fonts', []);
        $family = $map[$locale] ?? null;

        if (is_string($family) && isset(((array) config('legal_pdf.fonts', []))[$family])) {
            return $family;
        }

        return (string) config('legal_pdf.default_font', 'sarabun');
    }

    /**
     * @throws LegalPdfNotAvailableException
     */
    private function assertFontsInstalled(): void
    {
        $fontDir = rtrim((string) config('legal_pdf.font_dir', resource_path('fonts')), '/');

        foreach ((array) config('legal_pdf.fonts', []) as $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $folder = (string) ($definition['folder'] ?? '');

            foreach ((array) ($definition['files'] ?? []) as $file) {
                $path = $fontDir.'/'.$folder.'/'.(string) $file;

                if (! is_file($path)) {
                    throw LegalPdfNotAvailableException::fontsMissing($fontDir.'/'.$folder);
                }
            }
        }
    }

    private function tempPath(): string
    {
        $configured = (string) config('legal_pdf.temp_path', storage_path('app/private/mpdf'));
        $path = $configured !== '' ? $configured : storage_path('app/private/mpdf');

        if (! is_dir($path)) {
            @mkdir($path, 0770, true);
        }

        return is_dir($path) ? $path : sys_get_temp_dir();
    }

    private function operatorName(): string
    {
        $operator = (array) config('legal.operator', []);
        $name = $operator['legal_name'] ?? null;

        if (! is_string($name) || trim($name) === '') {
            // Never invent an operator identity: name the state instead.
            return (string) trans('public_pages.not_configured', [], 'en');
        }

        return trim($name);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function filename(string $type, string $locale, array $document): string
    {
        $version = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $document['version']) ?? 'v0';
        $version = trim($version, '-');
        $version = $version === '' ? 'v0' : $version;

        return sprintf(
            '%s-%s-%s-%s.pdf',
            $type,
            $locale,
            $version,
            substr((string) $document['content_hash'], 0, 16),
        );
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function storagePath(string $type, string $locale, array $document): string
    {
        $root = trim((string) config('legal_pdf.storage_path', 'legal-pdf'), '/');
        $version = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $document['version']) ?? 'v0';

        return sprintf(
            '%s/%s/%s/%s-%s.pdf',
            $root,
            $type,
            $locale,
            trim($version, '-') === '' ? 'v0' : trim($version, '-'),
            substr((string) $document['content_hash'], 0, 16),
        );
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function fromCache(string $type, string $locale, array $document): ?LegalPdfDocument
    {
        $ttl = (int) config('legal_pdf.cache_ttl_minutes', 0);

        if ($ttl <= 0) {
            return null;
        }

        $key = $this->cacheKey($type, $locale, (string) $document['version'], (string) $document['content_hash']);
        $entry = Cache::get($key);

        if (! is_array($entry) || ! is_string($entry['path'] ?? null)) {
            return null;
        }

        $disk = Storage::disk($this->disk());

        if (! $disk->exists($entry['path'])) {
            Cache::forget($key);

            return null;
        }

        $contents = $disk->get($entry['path']);

        if (! is_string($contents) || ! str_starts_with($contents, '%PDF-')) {
            Cache::forget($key);

            return null;
        }

        return new LegalPdfDocument(
            contents: $contents,
            filename: (string) ($entry['filename'] ?? $this->filename($type, $locale, $document)),
            sha256: hash('sha256', $contents),
            version: (string) ($entry['version'] ?? $document['version']),
        );
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function remember(string $type, string $locale, array $document, LegalPdfDocument $pdf): void
    {
        $ttl = (int) config('legal_pdf.cache_ttl_minutes', 0);

        if ($ttl <= 0) {
            return;
        }

        $path = $this->storagePath($type, $locale, $document);

        Storage::disk($this->disk())->put($path, $pdf->contents);

        Cache::put(
            $this->cacheKey($type, $locale, (string) $document['version'], (string) $document['content_hash']),
            [
                'path' => $path,
                'filename' => $pdf->filename,
                'version' => $pdf->version,
                'sha256' => $pdf->sha256,
            ],
            now()->addMinutes($ttl),
        );
    }

    private function disk(): string
    {
        return (string) config('legal_pdf.storage_disk', 'local');
    }

    /**
     * A mutable row behind an immutable hash is a compliance incident, not a
     * rendering problem: it is audited before the export is refused.
     *
     * @param  array<string, mixed>  $data
     */
    private function auditHashMismatch(string $type, string $locale, string $version, array $data): void
    {
        $this->audit->log(
            userId: null,
            action: AuditAction::SecurityAlert,
            riskLevel: RiskLevel::High,
            auditable: null,
            description: 'Refused to export a legal document whose stored content hash does not match its content.',
            metadata: [
                'document_type' => $type,
                'locale' => $locale,
                'version' => $version,
                'legal_document_id' => isset($data['legal_document_id']) ? (int) $data['legal_document_id'] : null,
                'reason' => LegalPdfNotAvailableException::REASON_HASH_MISMATCH,
            ],
        );
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->sortRecursively($item);
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
