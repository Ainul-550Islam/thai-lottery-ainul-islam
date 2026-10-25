<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTOs\Legal\LegalPdfDocument;
use App\Exceptions\Legal\LegalPdfNotAvailableException;
use App\Services\Legal\LegalDocumentService;
use App\Services\PublicPages\LegalPdfExportService;
use App\Services\PublicPages\PublicPageDataService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * Public Terms compatibility controller.
 *
 * Web rendering is owned by PublicPagesController. The read APIs expose the
 * same current versioned document; acceptance is handled by the authenticated
 * legal-consent controller, and no PDF is generated without a verified exporter.
 *
 * THE DOWNLOAD IS A REAL EXPORT OR IT IS THE OLD 501 — never a promise of one.
 * While LEGAL_PDF_ENABLED is false, downloadTermsApi() answers the exact byte
 * body it always answered, so an existing client sees no change. When the lane
 * is on, the PDF comes from App\Services\PublicPages\LegalPdfExportService: a
 * published revision whose stored content hash matched when it was rendered.
 * An unpublished, tampered or unrenderable document is a safe 404, never a
 * print-to-PDF substitute carrying the platform's name.
 */
final class PublicLegalTermsController extends Controller
{
    public function __construct(
        private readonly PublicPageDataService $pages,
        private readonly LegalDocumentService $legalDocuments,
        private readonly LegalPdfExportService $pdfExports,
    ) {
    }

    public function index(Request $request): View
    {
        $locale = $this->locale($request);
        $terms = $this->legalDocuments->currentData('terms', $locale);
        $meta = $this->legalMeta($this->pages->meta('terms', $locale), $terms);

        return view('terms.index', [
            'terms' => $terms + $meta,
            'meta' => $meta,
        ]);
    }

    public function getTermsApi(Request $request): JsonResponse
    {
        $locale = $this->locale($request);
        $terms = $this->legalDocuments->currentData('terms', $locale);
        $meta = $this->legalMeta($this->pages->meta('terms', $locale), $terms);

        return response()->json([
            'success' => true,
            'data' => $terms,
            'meta' => $meta + [
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function searchTermsApi(Request $request): JsonResponse
    {
        $locale = $this->locale($request);
        $query = trim((string) $request->query('q', ''));
        $terms = $this->legalDocuments->currentData('terms', $locale);
        $sections = is_array($terms['sections'] ?? null) ? $terms['sections'] : [];

        if ($query === '') {
            return response()->json(['success' => true, 'query' => '', 'count' => count($sections), 'results' => $sections]);
        }

        $needle = mb_strtolower($query);
        $results = array_values(array_filter($sections, static function (array $section) use ($needle): bool {
            $values = [(string) ($section['title'] ?? ''), (string) ($section['body'] ?? '')];
            foreach ((array) ($section['extra'] ?? []) as $extra) {
                $values[] = (string) $extra;
            }

            return str_contains(mb_strtolower(implode(' ', $values)), $needle);
        }));

        return response()->json([
            'success' => true,
            'query' => $query,
            'count' => count($results),
            'results' => $results,
        ]);
    }

    public function downloadTermsApi(Request $request): JsonResponse|Response
    {
        if (! $this->pdfExports->enabled()) {
            return response()->json([
                'success' => false,
                'state' => 'NOT_CONFIGURED',
                'message' => 'A verified Terms PDF export is not configured for this environment. Use the browser print or save control.',
            ], 501);
        }

        $locale = $this->downloadLocale($request);

        if ($locale === null) {
            return response()->json([
                'success' => false,
                'state' => 'UNSUPPORTED_LOCALE',
                'message' => 'That document language is not published for download in this environment.',
                'supported_locales' => $this->pdfExports->supportedLocales(),
            ], 422);
        }

        try {
            $document = $this->pdfExports->export(LegalPdfExportService::TYPE_TERMS, $locale);
        } catch (LegalPdfNotAvailableException $exception) {
            return response()->json([
                'success' => false,
                'state' => 'NOT_AVAILABLE',
                'code' => $exception->errorCode(),
                'message' => 'A verified PDF of the published Terms is not available. Use the browser print or save control.',
            ], 404);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'state' => 'NOT_CONFIGURED',
                'message' => 'The verified Terms PDF export is enabled but is not correctly configured for this environment.',
            ], 503);
        }

        return $this->pdfResponse($request, $document);
    }

    /**
     * @param array<string, string> $meta
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function legalMeta(array $meta, array $data): array
    {
        $title = (string) ($data['meta_title'] ?? $meta['title'] ?? '');
        $description = (string) ($data['meta_description'] ?? $meta['description'] ?? '');

        return array_replace($meta, [
            'title' => $title,
            'description' => $description,
            'og_title' => $title,
            'og_description' => $description,
        ]);
    }

    /**
     * The locale this request is being served in.
     *
     * Read from the container, never from the query string. App\Http\Middleware\
     * SetLocale already negotiated ?lang=/?locale=, the session and the cookie and
     * called app()->setLocale(); a controller that re-read ?locale= could only
     * disagree with the language the middleware chose, and it accepted only 'en'
     * and 'th', so a Bangla request would have been rendered as English content
     * inside a Bangla document.
     */
    private function locale(Request $request): string
    {
        return (string) app()->getLocale();
    }

    /**
     * The language a DOWNLOAD asks for.
     *
     * A file is not a negotiated page: it is stored, forwarded and re-read
     * outside a browser session, so the language it was rendered in has to be
     * explicit. ?locale= therefore names the file language here (unlike the
     * HTML/data endpoints above, which keep using the middleware's choice), and
     * an unsupported value is refused instead of silently rendered as English.
     * With no parameter the negotiated locale is used, and an unsupported
     * negotiated locale falls back to English only when English is supported.
     */
    private function downloadLocale(Request $request): ?string
    {
        $supported = $this->pdfExports->supportedLocales();
        $requested = strtolower(trim((string) $request->query('locale', '')));

        if ($requested !== '') {
            return in_array($requested, $supported, true) ? $requested : null;
        }

        $negotiated = strtolower((string) app()->getLocale());

        if (in_array($negotiated, $supported, true)) {
            return $negotiated;
        }

        return in_array('en', $supported, true) ? 'en' : null;
    }

    /**
     * Stream a verified export with the headers a download needs.
     */
    private function pdfResponse(Request $request, LegalPdfDocument $document): Response
    {
        $headers = [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$document->filename.'"',
            'Content-Length' => (string) $document->length(),
            'Content-Transfer-Encoding' => 'binary',
            'X-Content-Type-Options' => 'nosniff',
            'X-Document-Version' => $document->version,
            'ETag' => $document->etag(),
            'Cache-Control' => 'public, max-age=0, must-revalidate',
        ];

        // A client that already holds this exact revision gets 304 and no body.
        $ifNoneMatch = trim((string) $request->headers->get('If-None-Match', ''));

        if ($ifNoneMatch !== '' && hash_equals($document->etag(), $ifNoneMatch)) {
            // A 304 carries no body, so it must not advertise the length of the
            // one it replaced.
            unset($headers['Content-Length'], $headers['Content-Transfer-Encoding']);

            return response('', 304, $headers);
        }

        return response($document->contents, 200, $headers);
    }
}
