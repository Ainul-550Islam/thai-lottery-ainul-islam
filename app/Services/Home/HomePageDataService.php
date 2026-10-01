<?php

declare(strict_types=1);

namespace App\Services\Home;

use App\Services\Lottery\GloPublicHomeService;
use App\Services\Lottery\GloPublicStatsService;
use App\Services\Media\PublicAppLinkService;
use App\Services\Payments\PublicPaymentMethodsService;
use App\Services\Promotions\PublicBonusService;
use App\Services\Support\PublicSupportService;

/**
 * Single composition point for the public Home page.
 *
 * HomeController calls this — Blade never runs multi-table queries. Every
 * section carries an explicit status string (VERIFIED / CONFIGURED /
 * AVAILABLE / NOT_CONFIGURED / UNAVAILABLE / NO_VERIFIED_RESULT) so views
 * never rely on truthy/falsey ambiguity. A failing subsection is reported,
 * logged and degraded without aborting the whole page.
 */
class HomePageDataService
{
    public function __construct(
        private readonly GloPublicHomeService $gloHome,
        private readonly GloPublicStatsService $stats,
        private readonly PublicBonusService $bonuses,
        private readonly PublicPaymentMethodsService $payments,
        private readonly PublicSupportService $support,
        private readonly PublicAppLinkService $appLinks,
        private readonly PublicLaneResultDigestService $laneResults,
        private readonly HomeCountdownService $countdown,
        private readonly HomeLotteryFeedService $lotteryFeed,
        private readonly HomeResultFeedService $resultFeed,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function pageData(): array
    {
        $text = $this->text();
        $nextDraw = $this->section(fn (): array => $this->gloHome->nextDrawCard());
        $countdownData = $this->section(fn (): array => $this->countdown->getNextDrawTarget());
        $lotteriesData = $this->section(fn (): array => $this->lotteryFeed->getLotteryProducts());
        $resultsFeedData = $this->section(fn (): array => $this->resultFeed->getLatestResultsFeed());

        $hero = [
            'status' => 'VERIFIED',
            'app_name' => (string) config('app.name', 'Thai Lottery'),
            'draw_status' => $nextDraw['draw_status'] ?? null,
            'next_draw_iso' => $nextDraw['scheduled_at_iso'] ?? null,
            'timezone' => $nextDraw['timezone'] ?? 'Asia/Bangkok',
            'countdown' => $countdownData,
        ];

        return [
            'hero' => $hero,
            'current_result' => $this->section(fn (): array => $this->gloHome->currentResultCard()),
            'next_draw' => $nextDraw,
            'countdown' => $countdownData,
            'live_draw' => $this->section(fn (): array => $this->gloHome->liveCard()),
            // The four public result lanes. Wrapped like every other section,
            // so a lane outage degrades this block instead of the page.
            'lane_results' => $this->section(fn (): array => $this->laneResults->lanes()),
            'lottery_feed' => $lotteriesData,
            'result_feed' => $resultsFeedData,
            'stats' => $this->section(fn (): array => $this->stats->publicStats()),
            'prize_highlight' => $this->section(fn (): array => $this->gloHome->prizeCard()),
            'bonuses' => $this->section(fn (): array => $this->bonuses->activeCampaigns()),
            'payment_methods' => $this->section(fn (): array => $this->payments->publicMethods()),
            'support' => $this->section(fn (): array => $this->support->contact()),
            'app_links' => $this->section(fn (): array => $this->appLinks->links()),
            'trust' => [
                'status' => 'VERIFIED',
                'bullets' => array_values(array_map('strval', (array) config('home.trust.bullets', [
                    'Officially ingested from Government Lottery Office feeds',
                    '256-bit SSL encrypted transactions with bank-grade security',
                    'Transparent draw verification and immutable provenance',
                    '24/7 dedicated multi-channel player support',
                ]))),
                'message' => null,
            ],
            'products' => $this->section(fn (): array => $this->gloHome->productSummary()),
            'navigation' => $this->navigation(),
            'text' => $text,
            'csrf' => csrf_token(),
        ];
    }

    /**
     * Run one section builder; never let it take down the page.
     *
     * @return array<string, mixed>
     */
    private function section(callable $builder): array
    {
        try {
            $data = $builder();

            return is_array($data) ? $data : ['status' => 'UNAVAILABLE'];
        } catch (\Throwable $e) {
            report($e);

            return [
                'status' => 'UNAVAILABLE',
                'message' => 'This section is temporarily unavailable.',
            ];
        }
    }

    /**
     * @return list<array{label: string, route: string, url: string, active: bool}>
     */
    private function navigation(): array
    {
        $items = [
            ['label' => 'Home', 'route' => 'home'],
            ['label' => 'Results', 'route' => 'results.index'],
            ['label' => 'Ticket Checker', 'route' => 'ticket-check'],
            ['label' => 'Sales Points', 'route' => 'sales-points'],
            ['label' => 'National Lottery', 'route' => 'national-lottery.index'],
            ['label' => 'Weekly Lottery', 'route' => 'weekly-lottery.index'],
            ['label' => 'Bingo Speed', 'route' => 'bingo-lottery.index'],
            ['label' => 'PCSO 6D', 'route' => 'pcso-lottery.index'],
        ];

        $path = '';

        try {
            $path = request()->path();
        } catch (\Throwable) {
            // CLI / queue: leave path empty → no active link.
        }

        $out = [];

        foreach ($items as $item) {
            $url = '#';

            try {
                $url = route($item['route']);
            } catch (\Throwable) {
                // Route not registered in this environment: leave '#'.
            }

            $active = false;

            if ($path !== '') {
                $trimmedUrl = trim((string) parse_url($url, PHP_URL_PATH), '/');
                $trimmedPath = trim($path, '/');
                $active = $trimmedUrl === $trimmedPath;
            }

            $out[] = [
                'label' => $item['label'],
                'route' => $item['route'],
                'url' => $url,
                'active' => $active,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function text(): array
    {
        $locale = (string) app()->getLocale();

        return [
            'meta_title' => (string) trans('home.meta_title', [], $locale),
            'meta_description' => (string) trans('home.meta_description', [], $locale),
            'hero_title' => (string) trans('home.hero_title', [], $locale),
            'hero_lead' => (string) trans('home.hero_lead', [], $locale),
            'cta_results' => (string) trans('home.cta_results', [], $locale),
            'cta_check' => (string) trans('home.cta_check', [], $locale),
            'cta_sales' => (string) trans('home.cta_sales', [], $locale),
            'cta_sign_in' => (string) trans('home.cta_sign_in', [], $locale),
            'cta_register' => (string) trans('home.cta_register', [], $locale),
            'lane_results_title' => (string) trans('home.lane_results_title', [], $locale),
            'current_result_title' => (string) trans('home.current_result_title', [], $locale),
            'next_draw_title' => (string) trans('home.next_draw_title', [], $locale),
            'live_title' => (string) trans('home.live_title', [], $locale),
            'check_title' => (string) trans('home.check_title', [], $locale),
            'check_label' => (string) trans('home.check_label', [], $locale),
            'check_placeholder' => (string) trans('home.check_placeholder', [], $locale),
            'check_button' => (string) trans('home.check_button', [], $locale),
            'check_help' => (string) trans('home.check_help', [], $locale),
            'sales_title' => (string) trans('home.sales_title', [], $locale),
            'sales_help' => (string) trans('home.sales_help', [], $locale),
            'products_title' => (string) trans('home.products_title', [], $locale),
            'prize_title' => (string) trans('home.prize_title', [], $locale),
            'stats_title' => (string) trans('home.stats_title', [], $locale),
            'trust_title' => (string) trans('home.trust_title', [], $locale),
            'bonuses_title' => (string) trans('home.bonuses_title', [], $locale),
            'payments_title' => (string) trans('home.payments_title', [], $locale),
            'support_title' => (string) trans('home.support_title', [], $locale),
            'app_title' => (string) trans('home.app_title', [], $locale),
        ];
    }
}
