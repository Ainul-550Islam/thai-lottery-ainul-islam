<?php

declare(strict_types=1);

namespace App\Services\Home;

use App\Services\Lottery\GloPublicHomeService;
use App\Services\Lottery\GloPublicStatsService;
use App\Services\Media\PublicAppLinkService;
use App\Services\Payments\PublicPaymentMethodsService;
use App\Services\Promotions\PublicBonusService;
use App\Services\Support\PublicSupportService;
use Illuminate\Support\Facades\Route;

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
            'status' => 'AVAILABLE',
            'app_name' => (string) config('app.name', 'Thai Lottery'),
            'draw_status' => $nextDraw['draw_status'] ?? null,
            'next_draw_iso' => $nextDraw['scheduled_at_iso'] ?? null,
            'timezone' => $nextDraw['timezone'] ?? 'Asia/Bangkok',
            'countdown' => $countdownData,
        ];

        // Built once and shared by both the canonical key and its documented
        // alias below, so a section is never composed twice per request.
        $liveDraw = $this->section(fn (): array => $this->gloHome->liveCard());
        $laneResults = $this->section(fn (): array => $this->laneResults->lanes());
        $prizeHighlight = $this->section(fn (): array => $this->gloHome->prizeCard());
        $paymentMethods = $this->section(fn (): array => $this->payments->publicMethods());
        $trustBullets = $this->trustBullets();

        return [
            'hero' => $hero,
            'current_result' => $this->section(fn (): array => $this->gloHome->currentResultCard()),
            'next_draw' => $nextDraw,
            'countdown' => $countdownData,

            /*
             * SECTION KEYS CARRY BOTH NAMES.
             *
             * The published contract for this payload names four sections
             * `live`, `lanes`, `prize` and `payments`; the implementation grew
             * the longer `live_draw`, `lane_results`, `prize_highlight` and
             * `payment_methods`. Nothing reconciled the two, so any consumer
             * written against the contract read null from four of the twelve
             * sections and silently rendered an empty block.
             *
             * Both names are published and point at the same composed array.
             * The short names are the contract; the long names remain for the
             * Blade templates and the v1 API projection that already use them.
             */
            'live' => $liveDraw,
            'live_draw' => $liveDraw,
            // The four public result lanes. Wrapped like every other section,
            // so a lane outage degrades this block instead of the page.
            'lanes' => $laneResults,
            'lane_results' => $laneResults,
            'lottery_feed' => $lotteriesData,
            'result_feed' => $resultsFeedData,
            'stats' => $this->section(fn (): array => $this->stats->publicStats()),
            'prize' => $prizeHighlight,
            'prize_highlight' => $prizeHighlight,
            'bonuses' => $this->section(fn (): array => $this->bonuses->activeCampaigns()),
            'payments' => $paymentMethods,
            'payment_methods' => $paymentMethods,
            'support' => $this->section(fn (): array => $this->support->contact()),
            'app_links' => $this->section(fn (): array => $this->appLinks->links()),
            'trust' => [
                'status' => $trustBullets === [] ? 'NOT_CONFIGURED' : 'CONFIGURED',
                'bullets' => $trustBullets,
                'message' => null,
            ],
            'products' => $this->section(fn (): array => $this->gloHome->productSummary()),
            'navigation' => $this->navigation(),
            'text' => $text,
            'csrf' => csrf_token(),
        ];
    }

    /**
     * Trust claims, and the only two sources one may come from.
     *
     * An operator-configured bullet list always wins. When none is configured the
     * section falls back to the platform's own documented, verifiable statements —
     * every request is authorized server-side, and wallet credits are idempotent —
     * rather than to an empty block or, worse, an invented audit badge. Nothing
     * here names a certification the platform does not hold.
     *
     * @return list<string>
     */
    private function trustBullets(): array
    {
        $configured = array_values(array_filter(array_map(
            static fn (mixed $bullet): ?string => is_string($bullet) && trim($bullet) !== ''
                ? trim($bullet)
                : null,
            (array) config('home.trust.bullets', []),
        )));

        if ($configured !== []) {
            return $configured;
        }

        // A configured-but-unusable list is NOT a reason to substitute the
        // platform defaults: the operator asked to publish those entries and they
        // were rejected, so the section stays honestly NOT_CONFIGURED rather than
        // showing claims the operator did not choose.
        if ((array) config('home.trust.bullets', []) !== []) {
            return [];
        }

        $fallback = trans('home.trust_bullets');

        if (! is_array($fallback)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $bullet): ?string => is_string($bullet) && trim($bullet) !== ''
                ? trim($bullet)
                : null,
            $fallback,
        )));
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
            ['label_key' => 'home.nav_home', 'route' => 'home'],
            ['label_key' => 'home.nav_results', 'route' => 'results.index'],
            ['label_key' => 'home.nav_ticket_check', 'route' => 'ticket-check'],
            ['label_key' => 'home.nav_sales_points', 'route' => 'sales-points'],
            ['label_key' => 'home.nav_national_lottery', 'route' => 'national-lottery.index'],
            ['label_key' => 'home.nav_weekly_lottery', 'route' => 'weekly-lottery.index'],
            ['label_key' => 'home.nav_bingo', 'route' => 'bingo-lottery.index'],
            ['label_key' => 'home.nav_pcso', 'route' => 'pcso-lottery.index'],
        ];

        $path = '';

        try {
            $path = request()->path();
        } catch (\Throwable) {
            // CLI / queue: leave path empty → no active link.
        }

        $out = [];

        foreach ($items as $item) {
            // A missing route is not a valid public destination. Omit the item
            // rather than rendering a deceptive href="#" placeholder.
            if (! Route::has($item['route'])) {
                continue;
            }

            $url = route($item['route']);
            $active = false;

            if ($path !== '') {
                $trimmedUrl = trim((string) parse_url($url, PHP_URL_PATH), '/');
                $trimmedPath = trim($path, '/');
                $active = $trimmedUrl === $trimmedPath;
            }

            $out[] = [
                'label' => (string) trans($item['label_key'], [], app()->getLocale()),
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
            'independent_disclaimer' => (string) trans('home.independent_disclaimer', [], $locale),
        ];
    }
}
