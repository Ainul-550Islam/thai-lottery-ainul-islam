<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Media\AndroidApkReleaseService;
use App\Services\Media\DownloadTelemetryService;
use App\Services\PublicPages\PublicPageDataService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public application destination page and its two supporting endpoints.
 *
 * Only validated destinations from PublicAppLinkService and only a build the
 * AndroidApkReleaseService itself considers verified are exposed. This controller
 * invents no build version, package file, checksum or store badge: when there is
 * no verified release it says NOT_CONFIGURED instead of guessing.
 *
 * WHY THE ENDPOINTS WERE REWRITTEN
 * Both API actions used to return a hard-coded 501 NOT_CONFIGURED body regardless
 * of state, while AndroidApkReleaseService and DownloadTelemetryService - the two
 * services that implement exactly this - sat unused. A client therefore could never
 * verify a digest, and the download page could never show the evidence the
 * integrity section promises. They now read the same services the page reads.
 */
final class PublicDownloadAppController
{
    public function __construct(private readonly PublicPageDataService $pages)
    {
    }

    public function index(Request $request): View
    {
        $locale = $this->locale($request);
        $data = $this->pages->download($locale);
        $meta = $this->pages->meta('download', $locale);

        return view('download.index', [
            'download' => $data + $meta,
            'meta' => $meta,
            // Verified Android build, or null. The page renders the release block
            // only when this is non-null, so a missing build reads as
            // NOT_CONFIGURED rather than as a broken download.
            'release' => $this->verifiedRelease(),
        ]);
    }

    public function getBuildsApi(Request $request): JsonResponse
    {
        $locale = $this->locale($request);

        return response()->json([
            'success' => true,
            'data' => $this->pages->download($locale),
            'meta' => $this->pages->meta('download', $locale),
        ]);
    }

    /**
     * Compare a submitted SHA-256 with the digest of the binary we actually serve.
     *
     * THREE ANSWERS, NOT TWO
     *  - 501 NOT_CONFIGURED: there is no verified release, so there is nothing to
     *    compare against. Guessing a digest here would let a tampered client pass.
     *  - 422 INVALID_INPUT: the submitted value is not a 64-character hex digest.
     *    Refused before any comparison or file read.
     *  - 200 MATCH / NO_MATCH: a real comparison, in constant time (hash_equals).
     */
    public function verifyChecksumApi(Request $request): JsonResponse
    {
        // v1 contract: `success` mirrors the verdict, so a non-matching digest is
        // reported as an unsuccessful call.
        return $this->compareChecksum($request, 'MATCH', 'NO_MATCH', successMirrorsVerdict: true);
    }

    /**
     * Same comparison for the compatibility path, in the vocabulary that shipped first.
     *
     * WHY A SECOND ACTION EXISTS
     * Two consumers of this endpoint were pinned to different result labels: the
     * versioned public API contract says MATCH / NO_MATCH, while already-shipped
     * clients and the SEO integrity suite read MATCHED / MISMATCH. One action cannot
     * answer both without breaking one of them, so the legacy /api/download/*
     * alias - whose whole reason to exist is that older clients are hard-wired to
     * the older spelling - keeps the older labels. Every other field, and the
     * comparison itself, is byte-for-byte identical: compareChecksum() is the single
     * implementation and it only renames the two result words.
     */
    public function verifyChecksumLegacyApi(Request $request): JsonResponse
    {
        // Legacy contract: `success` means "the comparison ran", and the verdict is
        // carried by `state` / `verified`. Callers on this path already branch on
        // those two fields, so flipping `success` on a MISMATCH would turn a normal
        // "your file differs" answer into an apparent transport failure for them.
        return $this->compareChecksum($request, 'MATCHED', 'MISMATCH', successMirrorsVerdict: false);
    }

    private function compareChecksum(
        Request $request,
        string $matched,
        string $unmatched,
        bool $successMirrorsVerdict,
    ): JsonResponse {
        $release = $this->verifiedRelease();

        if ($release === null) {
            return response()->json([
                'success' => false,
                'state' => 'NOT_CONFIGURED',
                'message' => 'Checksum verification is not configured because no verified public build manifest is available.',
            ], 501);
        }

        $submitted = $request->input('sha256');
        $normalised = is_string($submitted) ? strtolower(trim($submitted)) : '';

        if (preg_match('/\A[a-f0-9]{64}\z/', $normalised) !== 1) {
            return response()->json([
                'success' => false,
                'state' => 'INVALID_INPUT',
                'message' => 'A SHA-256 digest is 64 hexadecimal characters.',
                'expected_sha256' => $release['sha256'],
            ], 422);
        }

        $isMatch = hash_equals($release['sha256'], $normalised);

        return response()->json([
            'success' => $successMirrorsVerdict ? $isMatch : true,
            'state' => $isMatch ? $matched : $unmatched,
            'verified' => $isMatch,
            'version' => $release['version'],
            'file' => $release['file'],
            'expected_sha256' => $release['sha256'],
        ]);
    }

    /**
     * Record one download attempt, without storing the client's IP.
     *
     * The digest is an HMAC of the address keyed with APP_KEY, so repeated clicks
     * from one client can be deduplicated without the table becoming a log of who
     * downloaded the app. A repeat inside the dedup window answers 201 with
     * recorded=false / deduplicated=true - the same success envelope, because the
     * client did nothing wrong.
     */
    public function trackDownloadApi(Request $request, DownloadTelemetryService $telemetry): JsonResponse
    {
        if (! $telemetry->isEnabled()) {
            return response()->json([
                'success' => false,
                'state' => 'NOT_CONFIGURED',
                'message' => 'Download tracking is not configured.',
            ], 501);
        }

        $platform = strtolower(trim((string) $request->input('platform', '')));

        if (! in_array($platform, DownloadTelemetryService::ALLOWED_PLATFORMS, true)) {
            return response()->json([
                'success' => false,
                'state' => 'INVALID_INPUT',
                'message' => 'platform must be one of: '.implode(', ', DownloadTelemetryService::ALLOWED_PLATFORMS),
                'allowed' => DownloadTelemetryService::ALLOWED_PLATFORMS,
            ], 422);
        }

        $release = $this->verifiedRelease();
        $version = is_string($request->input('version')) ? (string) $request->input('version') : ($release['version'] ?? null);

        $result = $telemetry->record(
            request: $request,
            platform: $platform,
            version: $version,
            sha256: $release['sha256'] ?? null,
            source: 'web',
        );

        return response()->json([
            'success' => true,
            'state' => 'RECORDED',
            'recorded' => $result['recorded'],
            'deduplicated' => $result['deduplicated'],
            'platform' => $result['platform'],
            'version' => $result['version'],
        ], 201);
    }

    /**
     * The verified release metadata, or null when the build cannot be vouched for.
     *
     * Wrapped rather than inlined so the page and both endpoints can never disagree
     * about whether a release exists.
     *
     * @return array<string, mixed>|null
     */
    private function verifiedRelease(): ?array
    {
        return app(AndroidApkReleaseService::class)->metadata();
    }

    /**
     * The locale SetLocale negotiated for this request.
     *
     * The query string is deliberately not consulted: the middleware has already
     * validated ?lang=/?locale= against SupportedLocale and called app()->setLocale(),
     * so reading it again here could only contradict the negotiated choice.
     */
    private function locale(Request $request): string
    {
        return (string) app()->getLocale();
    }
}
