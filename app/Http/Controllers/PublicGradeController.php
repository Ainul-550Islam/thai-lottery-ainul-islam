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

    /**
     * The locale this request is being served in.
     *
     * Read from the container, never from the query string. App\Http\Middleware\
     * SetLocale already negotiated ?lang=/?locale=, the session and the cookie and
     * called app()->setLocale(); a controller that re-read ?locale= could only
     * disagree with the language the middleware chose, and it accepted only 'en'
     * and 'th', so a Bangla request would have been rendered as English content
     * inside a Bangla document.
     */
    private function locale(Request $request): string
    {
        return (string) app()->getLocale();
    }
}
