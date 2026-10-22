<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PublicPages\PublicPageDataService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Compatibility controller for the public Privacy API.
 *
 * The canonical page source is PublicPageDataService. No legal identity,
 * retention period, regulator, DPO, certification, or download metadata is
 * invented here.
 */
final class PublicLegalPrivacyController extends Controller
{
    public function __construct(private readonly PublicPageDataService $pages)
    {
    }

    public function index(Request $request): View
    {
        $locale = $this->locale($request);
        $privacy = $this->pages->privacy($locale);
        $meta = $this->pages->meta('privacy', $locale);

        return view('privacy.index', [
            'privacy' => $privacy + $meta,
            'meta' => $meta,
        ]);
    }

    public function getPrivacyApi(Request $request): JsonResponse
    {
        $locale = $this->locale($request);

        return response()->json([
            'success' => true,
            'data' => $this->pages->privacy($locale),
            'meta' => $this->pages->meta('privacy', $locale),
        ]);
    }

    public function searchPrivacyApi(Request $request): JsonResponse
    {
        $locale = $this->locale($request);
        $query = trim((string) $request->query('q', ''));
        $data = $this->pages->privacy($locale);
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

    public function dsarRequestApi(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'state' => 'NOT_CONFIGURED',
            'message' => 'A verified privacy-request workflow is not configured for this environment. Use the contact page without sending identity documents through this public endpoint.',
        ], 501);
    }

    public function submitDsarApi(Request $request): JsonResponse
    {
        return $this->dsarRequestApi($request);
    }

    public function downloadPrivacyApi(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'state' => 'NOT_CONFIGURED',
            'message' => 'A verified Privacy Policy PDF export is not configured. Use the browser print or save control.',
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
