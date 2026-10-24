<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PublicPages\PublicPageDataService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public source-driven FAQ page.
 */
final class PublicFaqController
{
    public function __construct(private readonly PublicPageDataService $pages)
    {
    }

    public function index(Request $request): View
    {
        $locale = $this->locale($request);
        $data = $this->pages->faq($locale);
        $meta = $this->pages->meta('faq', $locale);

        return view('faq.index', [
            'faq' => $data + $meta,
            'meta' => $meta,
        ]);
    }

    public function getFaqsApi(Request $request): JsonResponse
    {
        $locale = $this->locale($request);

        return response()->json([
            'success' => true,
            'data' => $this->pages->faq($locale),
            'meta' => $this->pages->meta('faq', $locale),
        ]);
    }

    public function searchFaqsApi(Request $request): JsonResponse
    {
        $locale = $this->locale($request);
        $query = trim((string) $request->query('q', ''));
        $questions = $this->pages->faq($locale)['questions'] ?? [];
        $questions = is_array($questions) ? $questions : [];

        if ($query !== '') {
            $needle = mb_strtolower($query);
            $questions = array_values(array_filter($questions, static function (array $question) use ($needle): bool {
                return str_contains(mb_strtolower(implode(' ', [
                    (string) ($question['question'] ?? ''),
                    (string) ($question['answer'] ?? ''),
                ])), $needle);
            }));
        }

        return response()->json([
            'success' => true,
            'query' => $query,
            'count' => count($questions),
            'results' => $questions,
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
