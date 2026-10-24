<?php

declare(strict_types=1);

namespace App\Http\Controllers;

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
        return response()->json([
            'success' => false,
            'state' => 'NOT_CONFIGURED',
            'message' => 'Checksum verification is not configured because no verified public build manifest is available.',
        ], 501);
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
