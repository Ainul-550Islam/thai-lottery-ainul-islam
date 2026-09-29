<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BingoLotteryDraw;
use App\Models\NationalLotteryDraw;
use App\Models\PcsoLotteryDraw;
use App\Models\WeeklyLotteryDraw;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * XML SITEMAP (FINAL AUDIT #15).
 *
 * Canonical public URLs only:
 *  - the static public pages and the four lane indexes;
 *  - the year archives the lane navigation itself publishes;
 *  - the draw detail pages that exist in the database.
 *
 * Deliberately EXCLUDED: authentication, dashboard/wallet/player pages,
 * admin, API, the public search FORM endpoints (query-dependent), the
 * browser payment-return pages (per-user, no standalone content), and every
 * legacy `.php` URL — the bridge answers them with 301s, and sitemaps must
 * not canonise duplicates.
 *
 * Read-only and cacheable: it only SELECTs and renders.
 */
final class SitemapController
{
    /**
     * @var array<int, array{class-string, string}>
     */
    private const LANE_DRAW_MODELS = [
        [NationalLotteryDraw::class, 'national-lottery'],
        [WeeklyLotteryDraw::class, 'weekly-lottery'],
        [BingoLotteryDraw::class, 'bingo-lottery'],
        [PcsoLotteryDraw::class, 'pcso-lottery'],
    ];

    public function __invoke(): Response
    {
        $entries = [];

        foreach ($this->staticRoutes() as $routeName) {
            $entries[] = [
                'loc' => route($routeName),
                'changefreq' => $routeName === 'home' ? 'daily' : 'weekly',
                'priority' => $routeName === 'home' ? '1.0' : '0.8',
            ];
        }

        foreach (self::LANE_DRAW_MODELS as [$model, $prefix]) {
            // Lane index.
            $entries[] = [
                'loc' => route($prefix.'.index'),
                'changefreq' => 'daily',
                'priority' => '0.9',
            ];

            // Year archives: only years that actually have draws.
            $years = $model::query()
                ->distinct()
                ->orderBy('draw_year')
                ->pluck('draw_year');

            foreach ($years as $year) {
                $entries[] = [
                    'loc' => route($prefix.'.year', ['year' => (int) $year]),
                    'changefreq' => 'weekly',
                    'priority' => '0.7',
                ];
            }

            // Draw detail pages.
            $model::query()
                ->orderBy('draw_date')
                ->chunk(500, function ($draws) use (&$entries, $prefix): void {
                    foreach ($draws as $draw) {
                        $entries[] = [
                            'loc' => route($prefix.'.show', ['draw' => $draw->getRouteKey()]),
                            'lastmod' => Carbon::parse($draw->draw_date)->toDateString(),
                            'changefreq' => 'monthly',
                            'priority' => '0.6',
                        ];
                    }
                });
        }

        return response()
            ->view('sitemap', ['entries' => $entries], 200)
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    /**
     * Canonical public routes only — see the class docblock for what is
     * deliberately left out.
     *
     * @return array<int, string>
     */
    private function staticRoutes(): array
    {
        return [
            'home',
            'results.index',
            'ticket-check',
            'sales-points',
            'about',
            'vision',
            'terms',
            'privacy',
            'fees',
            'prize-verification',
            'discounts',
            'account-grades',
            'account-verification-guide',
            'contact',
        ];
    }
}
