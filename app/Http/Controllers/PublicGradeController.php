<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Lottery\DiscountParityProjectionService;
use App\Services\PublicPages\PublicPageDataService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Public account-grade catalogue.
 *
 * The public projection contains only configured programme rules. Personal
 * spend, current grade, history, and refresh operations remain authenticated.
 */
final class PublicGradeController
{
    public function __construct(
        private readonly PublicPageDataService $pages,
        private readonly DiscountParityProjectionService $matrix,
    ) {
    }

    public function index(Request $request): View
    {
        $locale = $this->locale($request);
        $data = $this->pages->grades($locale);
        $meta = $this->pages->meta('grades', $locale);
        $matrix = [];

        try {
            $matrix = $this->matrix->discountMatrix($locale);
        } catch (Throwable $exception) {
            report($exception);
        }

        return view('account-grade.index', [
            'grades' => $data + $meta,
            'matrix' => $matrix,
            'meta' => $meta,
        ]);
    }

    public function getLadderApi(Request $request): JsonResponse
    {
        $locale = $this->locale($request);

        return response()->json([
            'success' => true,
            'data' => $this->pages->grades($locale),
            'meta' => $this->pages->meta('grades', $locale),
        ]);
    }

    public function calculateGradeApi(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'state' => 'AUTHENTICATION_REQUIRED',
            'message' => 'A personal grade calculation requires the authenticated account context.',
        ], 401);
    }

    private function locale(Request $request): string
    {
        $candidate = (string) $request->query('locale', app()->getLocale());

        return in_array($candidate, ['en', 'th'], true)
            ? $candidate
            : (string) app()->getLocale();
    }
}
