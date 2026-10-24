<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PublicPages\FeesPageService;
use App\Services\PublicPages\PublicPageDataService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Public Fees compatibility controller.
 *
 * Fees are projected by FeesPageService from config/fees.php. The controller
 * never accepts a client-supplied fee, rate, currency, or discount value.
 */
final class PublicLegalFeesController extends Controller
{
    public function __construct(
        private readonly PublicPageDataService $pages,
        private readonly FeesPageService $fees,
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

    public function downloadFeesApi(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'state' => 'NOT_CONFIGURED',
            'message' => 'A verified fee-schedule PDF export is not configured. Use the browser print or save control.',
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
