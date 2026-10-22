<?php

declare(strict_types=1);

namespace App\Services\Home;

use App\Services\Lottery\GloPublicHomeService;
use Illuminate\Support\Facades\Cache;

/**
 * Projects only the GLO product catalogue already configured by the
 * authoritative product service. It intentionally does not invent or mark
 * third-party lottery products, schedules, payout rates, or availability as
 * verified.
 */
class HomeLotteryFeedService
{
    public function __construct(
        private readonly GloPublicHomeService $gloHome,
    ) {}

    /**
     * @return array{status: string, products: list<array<string, mixed>>, count: int, summary: array<string, mixed>|null}
     */
    public function getLotteryProducts(): array
    {
        try {
            return Cache::remember('home.lottery_feed.products', 60, function (): array {
                $summary = $this->gloHome->productSummary();
                $products = array_values(array_filter(
                    (array) ($summary['products'] ?? []),
                    static fn (mixed $product): bool => is_array($product)
                        && in_array((string) ($product['code'] ?? ''), ['L6', 'N3'], true),
                ));

                return [
                    'status' => $products === [] ? 'UNAVAILABLE' : 'CATALOGUE_ONLY',
                    'products' => $products,
                    'count' => count($products),
                    'summary' => $products === [] ? null : [
                        'status' => 'CATALOGUE_ONLY',
                        'products' => $products,
                    ],
                ];
            });
        } catch (\Throwable $e) {
            report($e);

            return [
                'status' => 'UNAVAILABLE',
                'products' => [],
                'count' => 0,
                'summary' => null,
            ];
        }
    }
}
