<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Affiliate\AffiliateCommissionDisplayService;
use App\Services\Lottery\DiscountParityProjectionService;
use App\Services\Pricing\LottoDiscountService;
use App\Services\Pricing\LottoPayoutRuleService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public Lotto Discount catalogue (PROMPT 4).
 *
 * NO PRICING LOGIC LIVES HERE. The controller selects a locale, optionally
 * narrows by product, and renders. Every number comes from
 * App\Services\Pricing\LottoDiscountService (discounts),
 * App\Services\Pricing\LottoPayoutRuleService (direct/reverse payout, read
 * from the same resolver the purchase engine uses) and
 * App\Services\Affiliate\AffiliateCommissionDisplayService (published bands).
 *
 * ANONYMOUS AND BOUNDED. GET /discounts requires no authentication. The
 * product filter is matched against the CONFIGURED product keys, so an
 * arbitrary value narrows to nothing instead of reaching a query builder,
 * and the catalogue itself is config-bounded by
 * config('discounts.page.max_rows').
 *
 * ONLY PUBLISHED ROWS. Disabled rules, non-public rules, expired rules and
 * internal bands are filtered inside the services; the view receives an
 * already-safe projection and renders it escaped.
 */
final class LottoDiscountController
{
    public function __construct(
        private readonly LottoDiscountService $discounts,
        private readonly LottoPayoutRuleService $payouts,
        private readonly AffiliateCommissionDisplayService $affiliate,
        private readonly DiscountParityProjectionService $matrixProjection,
    ) {}

    /**
     * GET /discounts
     */
    public function index(Request $request): View
    {
        $locale = (string) app()->getLocale();
        $catalogue = $this->discounts->publicCatalogue($locale);

        $filter = $this->productFilter($request, $catalogue['products']);

        if ($filter !== null) {
            $catalogue['products'] = array_values(array_filter(
                $catalogue['products'],
                static fn (array $product): bool => $product['product'] === $filter,
            ));
        }

        $canonical = rtrim((string) config('app.url'), '/').'/discounts';

        return view('discounts.index', [
            'meta' => [
                'title' => (string) trans('prize_discount.discount_meta_title', [], $locale),
                'description' => (string) trans('prize_discount.discount_meta_description', [], $locale),
                'canonical' => $canonical,
                'lang' => str_replace('_', '-', $locale),
                'og_title' => (string) trans('prize_discount.discount_meta_title', [], $locale),
                'og_description' => (string) trans('prize_discount.discount_meta_description', [], $locale),
                'og_type' => 'website',
                'og_url' => $canonical,
            ],
            'catalogue' => $catalogue,
            'filter' => $filter,
            'available_filters' => array_map(
                static fn (array $product): string => $product['product'],
                $this->discounts->publicCatalogue($locale)['products'],
            ),
            'payout_pairs' => $this->payouts->publicPairs(),
            'affiliate' => $this->affiliate->publicCatalogue(),
            'show_effective_period' => (bool) config('discounts.page.show_effective_period', true),
            // GRADE PARITY BATCH: the canonical National + Bangkok Weekly
            // game/prize/discount matrix, projected by the same service
            // the grade page and the calculators read. No fee, multiplier
            // or percentage is ever computed in this controller.
            'matrix' => $this->matrixProjection->discountMatrix($locale),
        ]);
    }

    /**
     * GET /api/v1/public/discounts — the same public projection as JSON.
     */
    public function indexApi(Request $request): JsonResponse
    {
        $locale = (string) app()->getLocale();
        $catalogue = $this->discounts->publicCatalogue($locale);

        $filter = $this->productFilter($request, $catalogue['products']);

        if ($filter !== null) {
            $catalogue['products'] = array_values(array_filter(
                $catalogue['products'],
                static fn (array $product): bool => $product['product'] === $filter,
            ));
        }

        return response()->json([
            'success' => true,
            'data' => [
                'catalogue' => $catalogue,
                'payout_pairs' => $this->payouts->publicPairs(),
                'affiliate' => $this->affiliate->publicCatalogue(),
                'matrix' => $this->matrixProjection->discountMatrix($locale),
            ],
        ]);
    }

    /**
     * Narrow by product, but only to a key the catalogue actually published.
     *
     * @param  list<array<string, mixed>>  $products
     */
    private function productFilter(Request $request, array $products): ?string
    {
        $requested = $request->query('product');

        if (! is_string($requested) || $requested === '' || strlen($requested) > 32) {
            return null;
        }

        $requested = strtolower($requested);

        foreach ($products as $product) {
            if ((string) $product['product'] === $requested) {
                return $requested;
            }
        }

        return null;
    }
}
