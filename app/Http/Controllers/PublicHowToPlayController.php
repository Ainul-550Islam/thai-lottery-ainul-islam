<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PublicPages\PublicPageDataService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public educational how-to-play page.
 *
 * The page describes configured platform steps without inventing payout
 * multipliers, draw dates, payment guarantees, or betting simulations.
 */
final class PublicHowToPlayController
{
    public function __construct(private readonly PublicPageDataService $pages)
    {
    }

    public function index(Request $request): View
    {
        $locale = $this->locale($request);
        $data = $this->pages->howToPlay($locale);
        $meta = $this->pages->meta('how-to-play', $locale);

        return view('how-to-play.index', [
            'howToPlay' => $data + $meta,
            'meta' => $meta,
        ]);
    }

    public function getRulesApi(Request $request): JsonResponse
    {
        $locale = $this->locale($request);

        return response()->json([
            'success' => true,
            'data' => $this->pages->howToPlay($locale),
            'meta' => $this->pages->meta('how-to-play', $locale),
        ]);
    }

    public function simulateBetTypeApi(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'state' => 'NOT_CONFIGURED',
            'message' => 'A public bet simulator is not configured. Educational content does not calculate or promise an outcome.',
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
