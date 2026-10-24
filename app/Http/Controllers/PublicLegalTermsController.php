<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Legal\LegalDocumentService;
use App\Services\PublicPages\PublicPageDataService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public Terms compatibility controller.
 *
 * Web rendering is owned by PublicPagesController. The read APIs expose the
 * same current versioned document; acceptance is handled by the authenticated
 * legal-consent controller, and no PDF is generated without a verified exporter.
 */
final class PublicLegalTermsController extends Controller
{
    public function __construct(
        private readonly PublicPageDataService $pages,
        private readonly LegalDocumentService $legalDocuments,
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

    public function downloadTermsApi(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'state' => 'NOT_CONFIGURED',
            'message' => 'A verified Terms PDF export is not configured for this environment. Use the browser print or save control.',
        ], 501);
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

    private function locale(Request $request): string
    {
        $candidate = (string) $request->query('locale', app()->getLocale());

        return in_array($candidate, ['en', 'th'], true)
            ? $candidate
            : (string) app()->getLocale();
    }
}
