<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Lottery\DiscountParityProjectionService;
use App\Services\Pricing\LottoDiscountService;
use App\Services\PublicPages\PublicPageDataService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

/**
 * Public discount catalogue.
 *
 * The server-side pricing service remains authoritative. No discount,
 * multiplier, tier, or payout amount is accepted from the browser.
 */
final class PublicLottoDiscountController
{
    public function __construct(
        private readonly PublicPageDataService $pages,
        private readonly LottoDiscountService $discounts,
        private readonly DiscountParityProjectionService $matrix,
    ) {
    }

    public function index(Request $request): View
    {
        $locale = $this->locale($request);
        $data = $this->pages->discounts($locale);
        $meta = $this->pages->meta('discounts', $locale);
        $matrix = [];

        try {
            $matrix = $this->matrix->discountMatrix($locale);
        } catch (Throwable $exception) {
            report($exception);
        }

        return view('discounts.index', [
            'discounts' => $data + $meta,
            'matrix' => $matrix,
            'meta' => $meta,
        ]);
    }

    public function getCatalogueApi(Request $request): JsonResponse
    {
        $locale = $this->locale($request);

        return response()->json([
            'success' => true,
            'data' => $this->pages->discounts($locale),
            'meta' => $this->pages->meta('discounts', $locale),
        ]);
    }

    public function calculatePayoutApi(Request $request): JsonResponse
    {
        $product = strtolower(trim((string) $request->input('product', '')));
        $market = $request->input('market');
        $base = trim((string) $request->input('base_amount', ''));

        if ($product === '' || preg_match('/^\d{1,13}(\.\d{1,2})?$/', $base) !== 1) {
            return response()->json(['success' => false, 'message' => 'Provide a published product and decimal base amount.'], 422);
        }

        try {
            $quote = $this->discounts->quote($product, is_string($market) ? $market : null, $base);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['success' => false, 'message' => 'That discount quote is not available.'], 422);
        }

        return response()->json(['success' => true, 'data' => $quote]);
    }

    private function locale(Request $request): string
    {
        $candidate = (string) $request->query('locale', app()->getLocale());

        return in_array($candidate, ['en', 'th'], true)
            ? $candidate
            : (string) app()->getLocale();
    }
}
