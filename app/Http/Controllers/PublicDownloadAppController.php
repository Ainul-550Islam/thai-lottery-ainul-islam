<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Media\AndroidApkReleaseService;
use App\Services\PublicPages\PublicPageDataService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public application destination page.
 *
 * Only validated destinations from PublicAppLinkService are exposed. This
 * controller does not invent build versions, package files, checksums, or
 * store badges.
 *
 * CHECKSUM VERIFICATION (F17 acceptance)
 * verifyChecksumApi compares a submitted SHA-256 digest against the digest of
 * the VERIFIED release the server is actually serving. It reads the same
 * AndroidApkReleaseService evidence the /app/thai-apk.apk endpoint gates on, so
 * the number a member verifies against can never be a number the server would
 * not itself accept. With no verified release the endpoint stays fail-closed at
 * 501 — an honest "not configured", never an invented digest.
 */
final class PublicDownloadAppController
{
    public function __construct(
        private readonly PublicPageDataService $pages,
        private readonly AndroidApkReleaseService $release,
    ) {
    }

    public function index(Request $request): View
    {
        $locale = $this->locale($request);
        $data = $this->pages->download($locale);
        $meta = $this->pages->meta('download', $locale);

        return view('download.index', [
            'download' => $data + $meta,
            'meta' => $meta,
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

    public function verifyChecksumApi(Request $request): JsonResponse
    {
        $metadata = $this->release->metadata();
        if ($metadata === null) {
            return response()->json([
                'success' => false,
                'state' => 'NOT_CONFIGURED',
                'message' => 'Checksum verification is not configured because no verified public build manifest is available.',
            ], 501);
        }

        $submitted = $request->input('sha256');
        if (! is_string($submitted) || trim($submitted) === '') {
            return response()->json([
                'success' => false,
                'state' => 'INVALID_INPUT',
                'message' => 'Provide the SHA-256 digest of the downloaded file.',
            ], 422);
        }

        $normalised = strtolower(trim($submitted));
        if (preg_match('/\A[a-f0-9]{64}\z/', $normalised) !== 1) {
            return response()->json([
                'success' => false,
                'state' => 'INVALID_INPUT',
                'message' => 'A SHA-256 digest is 64 hexadecimal characters.',
            ], 422);
        }

        $matches = hash_equals($metadata['sha256'], $normalised);

        return response()->json([
            'success' => $matches,
            'state' => $matches ? 'MATCH' : 'NO_MATCH',
            'message' => $matches
                ? 'The digest matches the verified release the server is serving.'
                : 'The digest does not match the verified release. Download the file again.',
            'file' => $metadata['file'],
            'version' => $metadata['version'],
            'sha256' => $metadata['sha256'],
        ]);
    }

    public function trackDownloadApi(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'state' => 'NOT_CONFIGURED',
            'message' => 'Download tracking is not configured.',
        ], 501);
    }

    private function locale(Request $request): string
    {
        $candidate = (string) $request->query('locale', app()->getLocale());

        return in_array($candidate, ['en', 'th'], true)
            ? $candidate
            : (string) app()->getLocale();
    }
}
