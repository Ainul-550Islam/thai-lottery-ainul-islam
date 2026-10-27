<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Legal document PDF export
|--------------------------------------------------------------------------
|
| The Terms / Privacy / Fees downloads are real, versioned, hash-stamped PDF
| exports ONLY when this lane is switched on AND the published document can be
| verified. The lane ships OFF: while LEGAL_PDF_ENABLED is false the download
| endpoints keep answering exactly what they answered before this feature
| existed (HTTP 501 + state NOT_CONFIGURED), so a client can never mistake a
| print-to-PDF browser control for a signed export.
|
| WHY mPDF
| The documents are Thai, English and Bangla. mPDF embeds a TrueType subset and
| shapes complex scripts with the font's own OpenType tables, which is what
| makes Thai tone marks/vowels and Bangla conjuncts render as words rather than
| as detached glyph clusters. No headless browser and no network are required.
|
| FONT CHOICE (verified against mPDF 8.3.1 in this repository)
|  - Sarabun (SIL OFL 1.1) covers Thai + Latin. It renders correctly with the
|    OpenType layout engine DISABLED, which is the safest configuration for
|    Thai: the script needs no ligature substitution, and enabling OTL is what
|    produced dotted-circle artefacts in testing.
|  - Bangla needs the OpenType layout engine (conjuncts, reordering). Hind
|    Siliguri (SIL OFL 1.1) shapes correctly with OTL enabled. Noto Sans
|    Bengali is NOT used: every build of it tested here makes mPDF 8.3.1's OTL
|    coverage reader fail (font glyphs outside the cmap), which aborts the
|    export rather than producing a document. An operator may point
|    LEGAL_PDF_BENGALI_FONT_FILES at another OFL Bangla family.
|
| NOTHING HERE IS A LEGAL APPROVAL. A PDF export reproduces what is already
| published; it never edits, approves, or re-versions a document.
|
*/

return [

    // Master switch. OFF by default: the endpoints behave exactly as before.
    'enabled' => filter_var(env('LEGAL_PDF_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    // Document types this exporter is allowed to render. 'privacy' is consumed
    // by the privacy-request batch through App\Services\PublicPages\LegalPdfExportService.
    'supported_types' => ['terms', 'privacy', 'fees'],

    // Locales a download may request. Anything else is rejected as a client
    // error rather than silently rendered in a different language.
    'supported_locales' => ['en', 'th', 'bn'],

    // How long a rendered PDF stays valid on the private disk. A re-render is
    // keyed by (type, locale, version, content_hash), so a new publication is a
    // cache miss even inside the TTL.
    'cache_ttl_minutes' => max(0, (int) env('LEGAL_PDF_CACHE_TTL_MINUTES', 1440)),

    /*
    |--------------------------------------------------------------------------
    | Fee schedule export
    |--------------------------------------------------------------------------
    |
    | The fee schedule is a TABLE, not prose, and it is the only export whose
    | content is generated from live configuration rather than from a published
    | legal document. These settings control that render.
    |
    | paper              A4 portrait; the schedule is one column of categories.
    | show_effective_at  stamp the fee schedule's own effective date on the page
    |                    so a dated PDF cannot be mistaken for a current one.
    | show_source        stamp the source reference for each row (the platform's
    |                    published fee basis), because a fee table without a
    |                    provenance column is a number with no author.
    | max_rows           a hard ceiling. A schedule that suddenly contains
    |                    thousands of rows is a data fault, and a 300-page PDF is
    |                    a denial of service on the operator's own mail relay.
    |
    | The renderer is App\Services\PublicPages\LegalPdfExportService through
    | App\Services\PublicPages\FeesPageService. No fee amount is computed here.
    */
    'fees' => [
        'paper' => 'A4',
        'orientation' => 'P',
        'show_effective_at' => true,
        'show_source' => true,
        'show_illustrative_notice' => true,
        'max_rows' => max(1, (int) env('LEGAL_PDF_FEES_MAX_ROWS', 500)),
        'title_key' => 'frontend_ops.fees.download_pdf',
        'illustrative_key' => 'frontend_ops.fees.illustrative',
    ],

    // Private disk: an export is an operator document, never a public asset.
    'storage_disk' => (string) env('LEGAL_PDF_STORAGE_DISK', 'local'),

    // Directory (inside the disk) holding cached renders.
    'storage_path' => 'legal-pdf',

    // Scratch directory for mPDF's font/glyph cache (must be writable).
    'temp_path' => (string) env('LEGAL_PDF_TEMP_PATH', storage_path('app/private/mpdf')),

    // Directory that contains the OFL font folders.
    'font_dir' => resource_path('fonts'),

    /*
    |--------------------------------------------------------------------------
    | Fonts
    |--------------------------------------------------------------------------
    |
    | 'files' are relative to the folder <font_dir>/<folder>. 'use_otl' is the
    | OpenType layout bitmask mPDF applies to the family (0 disables shaping).
    |
    */
    // Bengali is rendered by Hind Siliguri rather than Noto Sans Bengali: it is
    // the Bengali face this repository already ships (SIL OFL, two weights) and
    // mPDF shapes it correctly at use_otl 255. Shipping a second, unused Bengali
    // family would add megabyte-scale font payloads to every image and gain
    // nothing a reader could see. Swap the folder/files below if a deployment
    // prefers Noto; the renderer reads this map and nothing else.
    'fonts' => [
        'sarabun' => [
            'folder' => 'Sarabun',
            'files' => [
                'R' => 'Sarabun-Regular.ttf',
                'B' => 'Sarabun-Bold.ttf',
                'I' => 'Sarabun-Italic.ttf',
            ],
            'use_otl' => 0,
            'use_kashida' => 0,
        ],
        'bengali' => [
            'folder' => 'HindSiliguri',
            'files' => [
                'R' => 'HindSiliguri-Regular.ttf',
                'B' => 'HindSiliguri-Bold.ttf',
            ],
            'use_otl' => 255,
            'use_kashida' => 0,
        ],
    ],

    // Which font family renders which locale.
    'locale_fonts' => [
        'en' => 'sarabun',
        'th' => 'sarabun',
        'bn' => 'bengali',
    ],

    'default_font' => 'sarabun',

    // Kerning keeps Latin/number runs tight without touching the shaped scripts.
    'use_kerning' => true,

    /*
    |--------------------------------------------------------------------------
    | Paper
    |--------------------------------------------------------------------------
    */

    'paper' => [
        'size' => 'A4',
        'orientation' => 'P',
        // Millimetres, mPDF's unit for margins.
        'margin_top' => 18,
        'margin_bottom' => 22,
        'margin_left' => 16,
        'margin_right' => 16,
        'margin_header' => 8,
        'margin_footer' => 10,
        'header_rule' => true,
        'base_font_size_pt' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Metadata
    |--------------------------------------------------------------------------
    |
    | Author comes from config/legal.php ('operator.legal_name'). When the
    | operator has not published a name the export says so instead of inventing
    | one.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Watermark
    |--------------------------------------------------------------------------
    |
    | Empty by default: a legal document a person is entitled to read is not a
    | draft, and stamping it DRAFT/CONFIDENTIAL would say something untrue about
    | it. The key exists because the renderer reads it, not as a maybe.
    |
    */
    'watermark' => env('LEGAL_PDF_WATERMARK'),

    'metadata' => [
        'creator' => (string) env('LEGAL_PDF_CREATOR', 'Thai Lottery public legal documents'),
        'subject_prefix' => 'Legal document',
    ],

    // Keys consumed by the footer of every rendered page.
    'footer' => [
        'version_key' => 'public_pages.pdf_footer_version',
        'hash_key' => 'public_pages.pdf_footer_hash',
        'generated_key' => 'public_pages.pdf_footer_generated',
        'page_key' => 'public_pages.pdf_footer_page',
    ],

    // Number of hash characters shown in the footer. The full hash stays in the
    // ETag and in the response filename.
    'footer_hash_chars' => 16,

    // Never render a document whose stored hash does not match its content.
    'verify_content_hash' => true,

];
