<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PublicPages\PublicPageDataService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public Terms compatibility controller.
 *
 * Web rendering is owned by PublicPagesController. These API methods reuse
 * the same canonical Terms data source and fail closed where a real consent
 * ledger or PDF export service is not configured.
 */
final class PublicLegalTermsController extends Controller
{
    public function __construct(private readonly PublicPageDataService $pages)
    {
    }

    public function index(Request $request): View
    {
        $locale = (string) app()->getLocale();
        $terms = $this->pages->terms($locale);
        $meta = $this->pages->meta('terms', $locale);

        return view('terms.index', [
            'terms' => $terms,
            'meta' => $meta,
        ]);
    }

    public function getTermsApi(Request $request): JsonResponse
    {
        $locale = $this->locale($request);
        $terms = $this->pages->terms($locale);

        return response()->json([
            'success' => true,
            'data' => $terms,
            'meta' => $this->pages->meta('terms', $locale) + [
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function searchTermsApi(Request $request): JsonResponse
    {
        $locale = $this->locale($request);
        $query = trim((string) $request->query('q', ''));
        $terms = $this->pages->terms($locale);
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

    public function acceptTermsApi(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'state' => 'NOT_CONFIGURED',
            'message' => 'Terms consent recording is not configured for this environment.',
        ], 501);
    }

    public function downloadTermsApi(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'state' => 'NOT_CONFIGURED',
            'message' => 'A verified Terms PDF export is not configured for this environment. Use the browser print or save control.',
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
