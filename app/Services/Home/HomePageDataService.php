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
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function pageData(): array
    {
        $text = $this->text();

        return [
            'hero' => $this->section(fn (): array => $this->hero()),
            'current_result' => $this->section(fn (): array => $this->gloHome->currentResultCard()),
            'next_draw' => $this->section(fn (): array => $this->gloHome->nextDrawCard()),
            'live_draw' => $this->section(fn (): array => $this->gloHome->liveCard()),
            // The four public result lanes. Wrapped like every other section,
            // so a lane outage degrades this block instead of the page.
            'lane_results' => $this->section(fn (): array => $this->laneResults->lanes()),
            'stats' => $this->section(fn (): array => $this->stats->publicStats()),
            'prize_highlight' => $this->section(fn (): array => $this->gloHome->prizeCard()),
            'bonuses' => $this->section(fn (): array => $this->bonuses->activeCampaigns()),
            'payment_methods' => $this->section(fn (): array => $this->payments->publicMethods()),
            'support' => $this->section(fn (): array => $this->support->contact()),
            'app_links' => $this->section(fn (): array => $this->appLinks->links()),
            'trust' => [
                'status' => 'VERIFIED',
                'bullets' => array_values(array_map('strval', (array) config('home.trust.bullets', []))),
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
     * @return array<string, mixed>
     */
    private function hero(): array
    {
        $next = $this->gloHome->nextDrawCard();

        return [
            'status' => 'VERIFIED',
            'app_name' => (string) config('app.name', 'Thai Lottery'),
            'draw_status' => $next['draw_status'] ?? null,
            'next_draw_iso' => $next['scheduled_at_iso'] ?? null,
            'timezone' => $next['timezone'] ?? 'Asia/Bangkok',
        ];
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
        ];

        $path = '';

        try {
            $path = request()->path();
        } catch (\Throwable) {
            // CLI / queue: leave path empty → no active link.
        }

        $out = [];

        foreach ($items as $item) {
            try {
                $url = route($item['route']);
            } catch (\Throwable) {
                $url = '/';
            }

            $out[] = [
                'label' => $item['label'],
                'route' => $item['route'],
                'url' => $url,
                'active' => $path !== '' && parse_url((string) $url, PHP_URL_PATH) === '/'.$path,
            ];
        }

        return $out;
    }

    /**
     * Localised copy bag — controllers stay free of hard-coded UI prose.
     *
     * @return array<string, string>
     */
    private function text(): array
    {
        $locale = 'en';

        try {
            $locale = (string) app()->getLocale();
        } catch (\Throwable) {
            $locale = 'en';
        }

        /** @var array<string, string> $bag */
        $bag = trans('home', [], $locale);
        if (! is_array($bag)) {
            $bag = (array) include base_path('lang/en/home.php');
        }

        return $bag;
    }
}
