<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Lottery\PcsoLotteryDateService;
use App\Services\Lottery\PcsoLotteryHistoryService;
use App\Services\Lottery\PcsoLotteryPurchaseCapabilityService;
use App\Services\Lottery\PcsoLotteryResultService;
use App\Services\Lottery\PcsoLotterySearchService;
use App\Services\Lottery\PcsoLotterySourceService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Public PCSO Lottery result pages.
 *
 * This controller is deliberately thin. The PCSO lane has its own draw,
 * result, history, search, date and source services. It does not read GLO,
 * Mega, Weekly, National or operator-market data, and it never fabricates a
 * number, date, source, price or ticket.
 *
 * Route order is load-bearing in routes/web.php: search, buy, latest, history,
 * year, archive, draw and result are declared before the compatibility
 * wildcard. The wildcard remains available for the original /{draw} route.
 */
final class PcsoLotteryController
{
    public function __construct(
        private readonly PcsoLotteryResultService $results,
        private readonly PcsoLotteryHistoryService $history,
        private readonly PcsoLotterySearchService $search,
        private readonly PcsoLotterySourceService $sources,
        private readonly PcsoLotteryDateService $dates,
        private readonly PcsoLotteryPurchaseCapabilityService $purchaseCapability,
    ) {}

    /**
     * Page 37: PCSO public result landing page.
     */
    public function index(Request $request): View
    {
        unset($request);

        $current = $this->results->currentResult();
        $reference = $this->referenceFrom($current);
        $latestYear = $this->history->latestYear();

        return view('pcso-lottery.index', $this->pageData([
            'current' => $current,
            'recent' => $this->history->recentDraws($reference),
            'years' => $this->history->availableYears(),
            'active_year' => $latestYear,
            'history' => $latestYear === null ? null : $this->history->historyForYear($latestYear, 1),
            'search' => null,
            'page_variant' => 'home',
            'meta' => $this->meta(
                (string) trans('pcso_lottery.meta_title'),
                (string) trans('pcso_lottery.meta_description'),
                '/pcso-lottery',
                true,
            ),
        ]));
    }

    /**
     * Page 38: PCSO buy / ticket selection entry point.
     *
     * No purchase form is rendered until a complete product, draw, selection,
     * validation, price, responsible-gaming, wallet, reservation, issuance,
     * ledger and idempotency contract is verified.
     */
    public function buy(Request $request): View
    {
        unset($request);

        return view('pcso-lottery.buy', [
            'capability' => $this->purchaseCapability->capability(),
            'canonical' => rtrim((string) config('app.url'), '/').route('pcso-lottery.buy', [], false),
            'back_url' => route('pcso-lottery.index'),
            'meta' => $this->meta(
                (string) trans('pcso_lottery.meta_title').' — '.trans('pcso_lottery.buy_title'),
                (string) trans('pcso_lottery.meta_description'),
                '/pcso-lottery/buy',
                false,
            ),
        ]);
    }

    /**
     * Page 40: latest PCSO result.
     */
    public function latestResult(Request $request): View
    {
        unset($request);

        $current = $this->results->currentResult();
        $reference = $this->referenceFrom($current);

        return view('pcso-lottery.latest-result', $this->pageData([
            'current' => $current,
            'recent' => $this->history->recentDraws($reference),
            'years' => $this->history->availableYears(),
            'active_year' => null,
            'history' => null,
            'search' => null,
            'page_variant' => 'latest',
            'meta' => $this->meta(
                (string) trans('pcso_lottery.meta_title').' — '.trans('pcso_lottery.current_result_heading'),
                (string) trans('pcso_lottery.meta_description'),
                '/pcso-lottery/latest',
                (bool) ($current['available'] ?? false),
            ),
        ]));
    }

    /**
     * Page 41: historical PCSO results, starting with the latest available
     * year and preserving the service's pagination and source projection.
     */
    public function historicalResults(Request $request): View
    {
        $latestYear = $this->history->latestYear();
        $history = $latestYear === null
            ? null
            : $this->history->historyForYear($latestYear, $this->boundedPage($request));

        return view('pcso-lottery.history', $this->pageData([
            'current' => null,
            'recent' => [],
            'years' => $this->history->availableYears(),
            'active_year' => $latestYear,
            'history' => $history,
            'search' => null,
            'page_variant' => 'history',
            'meta' => $this->meta(
                (string) trans('pcso_lottery.meta_title').' — '.trans('pcso_lottery.history_heading'),
                (string) trans('pcso_lottery.meta_description'),
                '/pcso-lottery/history',
                $history !== null && ($history['status'] ?? '') === 'RESULT_FOUND',
            ),
        ]));
    }

    /**
     * Page 42: canonical PCSO year archive.
     */
    public function year(Request $request, string $year): View
    {
        if (preg_match('/^[0-9]{1,4}$/', $year) !== 1) {
            return $this->renderEmptyYear($year, 'INVALID_QUERY');
        }

        $gregorian = $this->dates->normaliseYearInput((int) $year);

        if ($gregorian === null) {
            return $this->renderEmptyYear($year, 'NO_PUBLIC_DATA');
        }

        $history = $this->history->historyForYear($gregorian, $this->boundedPage($request));
        $label = $this->dates->yearLabel($gregorian, $this->locale());

        return view('pcso-lottery.year', $this->pageData([
            'current' => null,
            'recent' => [],
            'years' => $this->history->availableYears(),
            'active_year' => $gregorian,
            'history' => $history,
            'search' => null,
            'page_variant' => 'year',
            'meta' => $this->meta(
                trans('pcso_lottery.meta_title').' — '.$label,
                (string) trans('pcso_lottery.meta_description_year', ['year' => $label]),
                '/pcso-lottery/year/'.$gregorian,
                (bool) config('pcso_lottery.seo.index_year_pages', true)
                    && ($history['status'] ?? '') === 'RESULT_FOUND',
            ),
        ]));
    }

    /**
     * Compatibility alias for the canonical /pcso-lottery/year/{year} route.
     */
    public function yearArchive(Request $request, string $year): View
    {
        return $this->year($request, $year);
    }

    /**
     * Page 39 and the original /pcso-lottery/{draw} detail route.
     */
    public function show(Request $request, string $draw): View
    {
        return $this->detail($request, $draw, '/pcso-lottery/'.$draw);
    }

    /**
     * Page 39 canonical draw-detail route.
     */
    public function drawDetail(Request $request, string $draw): View
    {
        return $this->detail($request, $draw, '/pcso-lottery/draw/'.$draw);
    }

    /**
     * Page 43 canonical result-detail route.
     */
    public function resultDetail(Request $request, string $draw): View
    {
        return $this->detail($request, $draw, '/pcso-lottery/result/'.$draw);
    }

    /**
     * GET /pcso-lottery/search — typed number or date lookup.
     */
    public function search(Request $request): View
    {
        $validated = $request->validate([
            'type' => ['nullable', 'string', 'in:'.implode(',', $this->search->searchTypes())],
            'term' => ['nullable', 'string', 'max:'.$this->search->maxTermLength()],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);

        $type = isset($validated['type']) && $validated['type'] !== '' ? (string) $validated['type'] : null;
        $term = isset($validated['term']) ? trim((string) $validated['term']) : '';
        $page = isset($validated['page']) ? (int) $validated['page'] : 1;
        $outcome = null;

        if ($term !== '') {
            $outcome = $type === null
                ? $this->search->search('', $term, $page)
                : $this->search->search($type, $term, $page);
        }

        return view('pcso-lottery.index', $this->pageData([
            'current' => null,
            'recent' => [],
            'years' => $this->history->availableYears(),
            'active_year' => null,
            'history' => null,
            'search' => $outcome,
            'page_variant' => 'search',
            'meta' => $this->meta(
                (string) trans('pcso_lottery.meta_title_search'),
                (string) trans('pcso_lottery.meta_description'),
                '/pcso-lottery/search',
                false,
            ),
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function pageData(array $data): array
    {
        return array_merge($data, [
            'locale' => $this->locale(),
            'is_thai' => $this->isThai(),
            'source_priority' => $this->sources->priority(),
            'official_configured' => $this->sources->officialConfigured(),
            'search_types' => $this->search->searchTypes(),
            'max_search_length' => $this->search->maxTermLength(),
            'routes' => [
                'index' => route('pcso-lottery.index'),
                'search' => route('pcso-lottery.search'),
                'buy' => route('pcso-lottery.buy'),
                'latest' => route('pcso-lottery.latest'),
                'history' => route('pcso-lottery.history'),
            ],
        ]);
    }

    private function detail(Request $request, string $draw, string $canonicalPath): View
    {
        unset($request);

        $projection = $this->results->resultForReference($draw);
        $title = (string) trans('pcso_lottery.detail_heading').' — '.trans('pcso_lottery.meta_title');

        if (($projection['available'] ?? false) === true && is_array($projection['draw'] ?? null)) {
            $date = is_array($projection['draw']['date'] ?? null) ? $projection['draw']['date'] : [];
            $label = $this->isThai() ? (string) ($date['display_th'] ?? '') : (string) ($date['display_en'] ?? '');
            if ($label !== '') {
                $title .= ' — '.$label;
            }
        }

        return view('pcso-lottery.show', $this->pageData([
            'current' => $projection,
            'recent' => [],
            'years' => $this->history->availableYears(),
            'active_year' => null,
            'history' => null,
            'search' => null,
            'page_variant' => 'detail',
            'meta' => $this->meta(
                $title,
                (string) trans('pcso_lottery.meta_description'),
                $canonicalPath,
                (bool) ($projection['available'] ?? false),
            ),
        ]));
    }

    private function renderEmptyYear(string $requested, string $status): View
    {
        $safeLabel = preg_match('/^[0-9]{1,4}$/', $requested) === 1 ? $requested : '';

        return view('pcso-lottery.year', $this->pageData([
            'current' => null,
            'recent' => [],
            'years' => $this->history->availableYears(),
            'active_year' => null,
            'history' => [
                'status' => $status,
                'year' => null,
                'rows' => [],
                'pagination' => [
                    'page' => 1,
                    'per_page' => 0,
                    'total' => 0,
                    'last_page' => 1,
                    'has_previous' => false,
                    'has_next' => false,
                ],
            ],
            'search' => null,
            'page_variant' => 'year',
            'meta' => $this->meta(
                trim(trans('pcso_lottery.meta_title').($safeLabel !== '' ? ' — '.$safeLabel : '')),
                (string) trans('pcso_lottery.meta_description'),
                '/pcso-lottery/year'.($safeLabel !== '' ? '/'.$safeLabel : ''),
                false,
            ),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(string $title, string $description, string $path, bool $indexable): array
    {
        $canonical = rtrim((string) config('app.url'), '/').$path;

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'lang' => str_replace('_', '-', $this->locale()),
            'robots' => $indexable ? 'index,follow' : 'noindex,follow',
            'og_title' => $title,
            'og_description' => $description,
            'og_type' => 'website',
            'og_url' => $canonical,
        ];
    }

    private function boundedPage(Request $request): int
    {
        $page = $request->query('page');

        if (! is_string($page) && ! is_int($page)) {
            return 1;
        }

        $page = (string) $page;
        if (preg_match('/^[0-9]{1,5}$/', $page) !== 1) {
            return 1;
        }

        return max(1, min((int) $page, 10000));
    }

    /**
     * @param  array<string, mixed>  $projection
     */
    private function referenceFrom(array $projection): ?string
    {
        return is_array($projection['draw'] ?? null) && isset($projection['draw']['reference'])
            ? (string) $projection['draw']['reference']
            : null;
    }

    private function locale(): string
    {
        return (string) app()->getLocale();
    }

    private function isThai(): bool
    {
        return str_starts_with(strtolower($this->locale()), 'th');
    }
}
