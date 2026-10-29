<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTOs\Legal\LegalPdfDocument;
use App\Exceptions\Legal\LegalPdfNotAvailableException;
use App\Services\PublicPages\FeesPageService;
use App\Services\PublicPages\LegalPdfExportService;
use App\Services\PublicPages\PublicPageDataService;
use App\Support\PublicPages\DownloadLanguage;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use Throwable;

/**
 * Public Fees compatibility controller.
 *
 * Fees are projected by FeesPageService from config/fees.php. The controller
 * never accepts a client-supplied fee, rate, currency, or discount value.
 *
 * The download follows the same rule as the Terms download: while
 * LEGAL_PDF_ENABLED is false it answers the exact body it always answered
 * (HTTP 501 + state NOT_CONFIGURED); when the lane is on it streams a PDF of
 * the published schedule, rendered from the same projection the page and the
 * JSON API use, so the file can never disagree with the page.
 */
final class PublicLegalFeesController extends Controller
{
    public function __construct(
        private readonly PublicPageDataService $pages,
        private readonly FeesPageService $fees,
        private readonly LegalPdfExportService $pdfExports,
    ) {
    }

    public function index(Request $request): View
    {
        $locale = $this->locale($request);
        $data = $this->pages->fees($locale);
        $meta = $this->pages->meta('fees', $locale);

        return view('fees.index', [
            'fees' => $data + $meta,
            'meta' => $meta,
        ]);
    }

    public function getFeesApi(Request $request): JsonResponse
    {
        $locale = $this->locale($request);

        return response()->json([
            'success' => true,
            'data' => $this->pages->fees($locale),
            'meta' => $this->pages->meta('fees', $locale),
        ]);
    }

    public function calculateFeeApi(Request $request): JsonResponse
    {
        $category = trim((string) $request->input('category', ''));
        $provider = $request->input('provider');
        $amount = trim((string) $request->input('amount', ''));

        if ($category === '' || preg_match('/^\d{1,13}(\.\d{1,2})?$/', $amount) !== 1) {
            return response()->json([
                'success' => false,
                'message' => 'Provide a published fee category and a decimal amount.',
            ], 422);
        }

        try {
            $result = $this->fees->calculateResult($category, $amount, is_string($provider) ? $provider : null);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'That fee preview is not available.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $result->toArray(),
        ]);
    }

    public function searchFeesApi(Request $request): JsonResponse
    {
        $locale = $this->locale($request);
        $query = trim((string) $request->query('q', ''));
        $rows = $this->pages->fees($locale)['rows'] ?? [];
        $rows = is_array($rows) ? $rows : [];

        if ($query !== '') {
            $needle = mb_strtolower($query);
            $rows = array_values(array_filter($rows, static function (array $row) use ($needle): bool {
                return str_contains(mb_strtolower(implode(' ', [
                    (string) ($row['label'] ?? ''),
                    (string) ($row['description'] ?? ''),
                    (string) ($row['group_label'] ?? ''),
                ])), $needle);
            }));
        }

        return response()->json(['success' => true, 'query' => $query, 'count' => count($rows), 'results' => $rows]);
    }

    public function downloadFeesApi(Request $request): JsonResponse|Response
    {
        if (! $this->pdfExports->enabled()) {
            return response()->json([
                'success' => false,
                'state' => 'NOT_CONFIGURED',
                'message' => 'A verified fee-schedule PDF export is not configured. Use the browser print or save control.',
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
            $document = $this->pdfExports->export(LegalPdfExportService::TYPE_FEES, $locale);
        } catch (LegalPdfNotAvailableException $exception) {
            return response()->json([
                'success' => false,
                'state' => 'NOT_AVAILABLE',
                'code' => $exception->errorCode(),
                'message' => 'A verified PDF of the published fee schedule is not available. Use the browser print or save control.',
            ], 404);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'state' => 'NOT_CONFIGURED',
                'message' => 'The verified fee-schedule PDF export is enabled but is not correctly configured for this environment.',
            ], 503);
        }

        return $this->pdfResponse($request, $document);
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
     * A file is stored and re-read outside a browser session, so the language
     * it was rendered in must be explicit. ?locale= names the file language
     * here (the HTML/data endpoints above keep using the middleware's choice),
     * and an unsupported value is refused rather than silently rendered as
     * English.
     */
    private function downloadLocale(Request $request): ?string
    {
        return app(DownloadLanguage::class)->resolve($request, $this->pdfExports->supportedLocales());
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
