<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ArticleStatus;
use App\Enums\DrawPublicationStatus;
use App\Models\Article;
use App\Models\BingoLotteryDraw;
use App\Models\NationalLotteryDraw;
use App\Models\PcsoLotteryDraw;
use App\Models\WeeklyLotteryDraw;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

/**
 * XML SITEMAP (FINAL AUDIT #15 & P1-29).
 *
 * Canonical public URLs only:
 *  - the static public pages and the four lane indexes;
 *  - the year archives the lane navigation itself publishes (only years with published draws);
 *  - the publicly live draw detail pages that exist in the database.
 *
 * Deliberately EXCLUDED: authentication, dashboard/wallet/player pages,
 * admin, API, unpublished/hidden draw rows, the public search FORM endpoints (query-dependent), the
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

        $now = now()->toDateString();
        $liveStates = [
            DrawPublicationStatus::Published->value,
            DrawPublicationStatus::RePublished->value,
        ];

        foreach (self::LANE_DRAW_MODELS as [$model, $prefix]) {
            // Lane index.
            $entries[] = [
                'loc' => route($prefix.'.index'),
                'changefreq' => 'daily',
                'priority' => '0.9',
            ];

            // Year archives: only years that actually have publicly published draws.
            $years = $model::query()
                ->whereIn('publication_status', $liveStates)
                ->where('draw_date', '<=', $now)
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

            // Draw detail pages: only publicly live draws.
            $model::query()
                ->whereIn('publication_status', $liveStates)
                ->where('draw_date', '<=', $now)
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

        foreach ($this->blogEntries() as $entry) {
            $entries[] = $entry;
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
            'lotteries.index',
            'results.index',
            'ticket-check',
            'sales-points',
            'about',
            'vision',
            // Public information pages that the shared footer and navigation link to.
            // `/services`, `/how-to-play`, `/faq`, `/download` and `/betting` were
            // reachable but absent from the sitemap, which the SEO contract test
            // (tests/Feature/SEO/SitemapTest.php) has always asserted.
            'services',
            'how-to-play',
            'faq',
            'download',
            'betting.index',
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

    /**
     * The trilingual blog: three index pages plus every published article, with
     * the first image of each article body attached as an image sitemap entry.
     *
     * WHY THE BLOG IS NOT IN staticRoutes()
     * Its URLs are per-locale (`/blog/en`, `/blog/th`, `/blog/bn`) and its article
     * list is data, not configuration, so it cannot be a fixed route-name list.
     * Google only accepts image entries for URLs already listed in the same
     * sitemap, which is why the image block rides on the article's own <url>.
     *
     * A missing or disabled blog yields no entries rather than an error: the
     * sitemap must keep serving the rest of the site.
     *
     * @return list<array<string, mixed>>
     */
    private function blogEntries(): array
    {
        if (! (bool) config('content.enabled', true) || ! Route::has('blog.index')) {
            return [];
        }

        $entries = [];

        foreach (\App\Support\SupportedLocale::ALL as $locale) {
            $entries[] = [
                'loc' => route('blog.index', ['locale' => $locale]),
                'changefreq' => 'weekly',
                'priority' => '0.7',
            ];
        }

        if (! Route::has('blog.show')) {
            return $entries;
        }

        Article::query()
            ->where('status', \App\Enums\ArticleStatus::Published)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->orderBy('published_at')
            ->chunk(200, function ($articles) use (&$entries): void {
                foreach ($articles as $article) {
                    if (! $article->isPubliclyReachable()) {
                        continue;
                    }

                    $entry = [
                        'loc' => route('blog.show', ['locale' => $article->locale, 'slug' => $article->slug]),
                        'lastmod' => $article->updated_at?->toDateString(),
                        'changefreq' => 'monthly',
                        'priority' => '0.6',
                    ];

                    $image = $this->firstImageOf((string) $article->body);
                    if ($image !== null) {
                        $entry['images'] = [[
                            'loc' => $image,
                            'title' => (string) ($article->meta_title ?: $article->title),
                        ]];
                    }

                    $entries[] = $entry;
                }
            });

        return $entries;
    }

    /**
     * The first absolute https image URL in an article body, or null.
     *
     * Only https and only absolute: a relative or http source would be rejected by
     * Google, and shipping a broken image entry is worse than shipping none.
     */
    private function firstImageOf(string $html): ?string
    {
        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $matches) !== 1) {
            return null;
        }

        $src = trim($matches[1]);

        return str_starts_with(strtolower($src), 'https://') ? $src : null;
    }
}
