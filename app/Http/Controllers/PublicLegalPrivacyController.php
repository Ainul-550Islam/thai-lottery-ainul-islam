<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTOs\Legal\LegalPdfDocument;
use App\Exceptions\Privacy\PrivacyRequestException;
use App\Exceptions\Legal\LegalPdfNotAvailableException;
use App\Enums\PrivacyRequestType;
use App\Http\Requests\Privacy\SubmitPrivacyRequestRequest;
use App\Services\Legal\LegalDocumentService;
use App\Services\Privacy\PrivacyRequestService;
use App\Services\PublicPages\LegalPdfExportService;
use App\Support\PublicPages\DownloadLanguage;
use App\Services\PublicPages\PublicPageDataService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * Compatibility controller for the public Privacy API.
 *
 * The canonical page source is PublicPageDataService. No legal identity,
 * retention period, regulator, DPO, certification, or download metadata is
 * invented here.
 *
 * TWO FLAG-GATED LANES LIVE HERE, AND BOTH ARE OFF BY DEFAULT.
 *
 *  - downloadPrivacyApi(): while LEGAL_PDF_ENABLED is false it answers the exact
 *    501 body it has always answered; when the lane is on it streams a verified
 *    PDF of the PUBLISHED Privacy Notice (see LegalPdfExportService for what
 *    "verified" means: a publication row, a content hash that still matches, an
 *    embedded font, no external asset).
 *
 *  - dsarRequestApi()/submitDsarApi(): while PRIVACY_REQUESTS_ENABLED is false
 *    they answer the exact 501 body they have always answered. When the lane is
 *    on, a valid submission returns HTTP 202 with ONE neutral sentence, whether
 *    or not the address belongs to an account, whether or not a request already
 *    existed, and whether or not the honeypot was tripped. There is no branch in
 *    this file that could tell a caller which of those happened.
 *
 * WHAT IS UNCHANGED ON PURPOSE: acceptance of the Terms and Privacy Notice is
 * still recorded only by the authenticated POST /legal/consent flow. No public
 * endpoint in this file writes a consent record.
 */
final class PublicLegalPrivacyController extends Controller
{
    public function __construct(
        private readonly PublicPageDataService $pages,
        private readonly LegalDocumentService $legalDocuments,
        private readonly LegalPdfExportService $pdfExports,
        private readonly PrivacyRequestService $privacyRequests,
    ) {
    }

    public function index(Request $request): View
    {
        $locale = $this->locale($request);
        $privacy = $this->legalDocuments->currentData('privacy', $locale);
        $meta = $this->legalMeta($this->pages->meta('privacy', $locale), $privacy);

        return view('privacy.index', [
            'privacy' => $privacy + $meta,
            'meta' => $meta,
        ]);
    }

    public function getPrivacyApi(Request $request): JsonResponse
    {
        $locale = $this->locale($request);

        $privacy = $this->legalDocuments->currentData('privacy', $locale);

        return response()->json([
            'success' => true,
            'data' => $privacy,
            'meta' => $this->legalMeta($this->pages->meta('privacy', $locale), $privacy),
        ]);
    }

    public function searchPrivacyApi(Request $request): JsonResponse
    {
        $locale = $this->locale($request);
        $query = trim((string) $request->query('q', ''));
        $data = $this->legalDocuments->currentData('privacy', $locale);
        $sections = is_array($data['sections'] ?? null) ? $data['sections'] : [];

        if ($query === '') {
            return response()->json(['success' => true, 'query' => '', 'count' => count($sections), 'results' => $sections]);
        }

        $needle = mb_strtolower($query);
        $results = array_values(array_filter($sections, static function (array $section) use ($needle): bool {
            return str_contains(mb_strtolower(implode(' ', [
                (string) ($section['title'] ?? ''),
                (string) ($section['body'] ?? ''),
            ])), $needle);
        }));

        return response()->json([
            'success' => true,
            'query' => $query,
            'count' => count($results),
            'results' => $results,
        ]);
    }

    /**
     * The routed public intake entry point.
     *
     * routes/api.php posts this URL at dsarRequestApi(), and the method honours
     * both shapes: a POST carries the request and is validated through
     * SubmitPrivacyRequestRequest; anything else describes the intake contract.
     * Both answer the same 501 while the lane is off.
     */
    public function dsarRequestApi(Request $request): JsonResponse
    {
        if (! $this->privacyRequests->enabled()) {
            return $this->privacyIntakeNotConfigured();
        }

        // A JSON client must get a JSON 422, not a redirect: this endpoint is
        // documented as an API and has no browser form behind it.
        $request->headers->set('Accept', 'application/json');

        if (! $request->isMethod('post')) {
            return response()->json([
                'success' => true,
                'state' => 'READY',
                'accepted_types' => PrivacyRequestType::acceptedValues(),
                'message' => (string) trans('privacy_requests.intake.accepted'),
            ]);
        }

        return $this->handlePublicIntake(SubmitPrivacyRequestRequest::createFromBase($request));
    }

    /**
     * Public intake for a data-subject request (typed entry point).
     *
     * ALWAYS 202 AND ALWAYS THE SAME BODY once the submission is well-formed.
     * A validation failure is the only other answer, and its fields are shape
     * checks that do not consult the database - so a 422 can never mean "that
     * address has no account".
     */
    public function submitDsarApi(SubmitPrivacyRequestRequest $request): JsonResponse
    {
        if (! $this->privacyRequests->enabled()) {
            return $this->privacyIntakeNotConfigured();
        }

        return $this->handlePublicIntake($request);
    }

    /**
     * Validate, submit, and answer neutrally.
     *
     * The validation exception is allowed to escape into Laravel's normal 422
     * rendering because every message it can produce is a shape message that is
     * identical for a known and an unknown address.
     */
    private function handlePublicIntake(SubmitPrivacyRequestRequest $request): JsonResponse
    {
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->validateResolved();

        try {
            $this->privacyRequests->submitPublic(
                email: (string) $request->input('email'),
                type: $request->requestType(),
                locale: $request->localeOr((string) app()->getLocale()),
                details: $request->details(),
                ip: $request->ip(),
                honeypotTripped: $request->honeypotTripped(),
            );
        } catch (PrivacyRequestException $exception) {
            if ($exception->errorCode() === PrivacyRequestException::CODE_DISABLED) {
                return $this->privacyIntakeNotConfigured();
            }

            return response()->json([
                'success' => false,
                'state' => 'INVALID_REQUEST',
                'message' => (string) trans('privacy_requests.intake.invalid'),
            ], 422);
        }

        // Deliberately identical for every outcome, including the honeypot and
        // an address already holding the maximum number of open requests.
        return $this->privacyIntakeAccepted();
    }

    /**
     * The verified Privacy Notice PDF.
     */
    public function downloadPrivacyApi(Request $request): JsonResponse|Response
    {
        if (! $this->pdfExports->enabled()) {
            return response()->json([
                'success' => false,
                'state' => 'NOT_CONFIGURED',
                'message' => 'A verified Privacy Policy PDF export is not configured. Use the browser print or save control.',
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
            $document = $this->pdfExports->export(LegalPdfExportService::TYPE_PRIVACY, $locale);
        } catch (LegalPdfNotAvailableException $exception) {
            return response()->json([
                'success' => false,
                'state' => 'NOT_AVAILABLE',
                'code' => $exception->errorCode(),
                'message' => 'A verified PDF of the published Privacy Notice is not available. Use the browser print or save control.',
            ], 404);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'state' => 'NOT_CONFIGURED',
                'message' => 'The verified Privacy Notice PDF export is enabled but is not correctly configured for this environment.',
            ], 503);
        }

        return $this->pdfResponse($request, $document);
    }

    /**
     * The one neutral intake answer.
     */
    private function privacyIntakeAccepted(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'state' => PrivacyRequestService::OUTCOME_ACCEPTED,
            'message' => (string) trans('privacy_requests.intake.accepted'),
        ], 202);
    }

    /**
     * The exact body this endpoint answered before the lane existed.
     */
    private function privacyIntakeNotConfigured(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'state' => 'NOT_CONFIGURED',
            'message' => 'A verified privacy-request workflow is not configured for this environment. Use the contact page without sending identity documents through this public endpoint.',
        ], 501);
    }

    /**
     * The language a DOWNLOAD asks for.
     *
     * A file is stored and re-read outside a browser session, so the language it
     * was rendered in must be explicit. ?locale= names the file language here
     * (the HTML/data endpoints above keep using the middleware's negotiated
     * choice), and an unsupported value is refused rather than silently rendered
     * as English.
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
            unset($headers['Content-Length'], $headers['Content-Transfer-Encoding']);

            return response('', 304, $headers);
        }

        return response($document->contents, 200, $headers);
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
}
