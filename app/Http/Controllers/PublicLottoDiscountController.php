<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Affiliate\AffiliateCommissionDisplayService;
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
        private readonly AffiliateCommissionDisplayService $affiliate,
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
            // The view has always read $affiliate, but nothing ever passed it, so the
            // affiliate section fell back to its "not published" branch no matter what
            // config('discounts.affiliate_display.publish') said. The catalogue below
            // is the same projection the JSON endpoint now returns.
            'affiliate' => $this->affiliate->publicCatalogue(),
        ]);
    }

    public function getCatalogueApi(Request $request): JsonResponse
    {
        $locale = $this->locale($request);

        $affiliate = $this->affiliate->publicCatalogue();

        return response()->json([
            'success' => true,
            'data' => $this->pages->discounts($locale) + ['affiliate' => $affiliate],
            'affiliate' => $affiliate,
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
