<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Lottery\WeeklyLotteryDateService;
use App\Services\Lottery\WeeklyLotteryHistoryService;
use App\Services\Lottery\WeeklyLotteryResultService;
use App\Services\Lottery\WeeklyLotterySearchService;
use App\Services\Lottery\WeeklyLotterySourceService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Public Weekly Lottery result pages (PROMPT 6).
 *
 * THIN. Every method validates the shape and the BOUNDS of its input, then
 * hands off to a service. There is no query, no date arithmetic, no source
 * reasoning and no caching decision in this file, so none of those exists in
 * two places.
 *
 * ANONYMOUS. All four routes are public GETs. /weekly-lottery/search carries
 * the 'weekly-result-search' limiter, because a public lookup over a
 * 1,000,000-value six-digit space is an enumeration oracle without one.
 *
 * WHAT THE CLIENT MAY SAY
 * ---------------------------------------------------------------------------
 * A year, a page, a draw reference, a search TYPE from a closed list, and a
 * term. Nothing else is read from the request. The client cannot ask for a
 * sort order, a column, a per-page beyond the configured ceiling, or a source
 * state - those are answers, not questions.
 *
 * ROUTE ORDER MATTERS AND IS FIXED IN routes/web.php: /search and
 * /year/{year} are declared BEFORE /{draw}, so a literal path can never be
 * swallowed by the wildcard and read as a draw reference.
 */
final class WeeklyLotteryController
{
    public function __construct(
        private readonly WeeklyLotteryResultService $results,
        private readonly WeeklyLotteryHistoryService $history,
        private readonly WeeklyLotterySearchService $search,
        private readonly WeeklyLotterySourceService $sources,
        private readonly WeeklyLotteryDateService $dates,
    ) {}

    /**
     * GET /weekly-lottery — current result plus recent draws.
     */
    public function index(Request $request): View
    {
        unset($request);

        $current = $this->results->currentResult();

        $reference = is_array($current['draw'] ?? null) && isset($current['draw']['reference'])
            ? (string) $current['draw']['reference']
            : null;

        // THE LANDING PAGE SHOWS THE MOST RECENT YEAR'S RESULTS.
        //
        // It previously passed history => null, so the table only ever
        // appeared on /year/{year}. A visitor arriving at the lane saw the
        // latest draw and a list of year links, and had to guess that the
        // results themselves were one click further in. The year list is
        // already a query over stored draws, so this costs one paginated
        // read of a year that certainly has data - and nothing at all when
        // the lane is empty.
        $latestYear = $this->history->latestYear();

        return view('weekly-lottery.index', $this->pageData([
            'current' => $current,
            'recent' => $this->history->recentDraws($reference),
            'years' => $this->history->availableYears(),
            'active_year' => $latestYear,
            'history' => $latestYear === null
                ? null
                : $this->history->historyForYear($latestYear, 1),
            'search' => null,
            'meta' => $this->meta(
                (string) trans('weekly_lottery.meta_title'),
                (string) trans('weekly_lottery.meta_description'),
                '/weekly-lottery',
                true,
            ),
        ]));
    }

    /**
     * GET /weekly-lottery/{draw} — one draw in detail.
     *
     * The route parameter is constrained to [A-Za-z0-9\-]{1,40} in
     * routes/web.php and re-checked inside the service, so a crafted
     * reference never reaches a query as anything but a bound string.
     */
    public function show(Request $request, string $draw): View
    {
        unset($request);

        $projection = $this->results->resultForReference($draw);

        $title = (string) trans('weekly_lottery.meta_title');

        if (($projection['available'] ?? false) === true && is_array($projection['draw'] ?? null)) {
            $date = $projection['draw']['date'] ?? [];
            $label = is_array($date)
                ? (string) ($this->isThai() ? ($date['display_th'] ?? '') : ($date['display_en'] ?? ''))
                : '';

            if ($label !== '') {
                $title .= ' — '.$label;
            }
        }

        return view('weekly-lottery.show', $this->pageData([
            'current' => $projection,
            'recent' => [],
            'years' => $this->history->availableYears(),
            'active_year' => null,
            'history' => null,
            'search' => null,
            'meta' => $this->meta(
                $title,
                (string) trans('weekly_lottery.meta_description'),
                '/weekly-lottery/'.$draw,
                ($projection['available'] ?? false) === true,
            ),
        ]));
    }

    /**
     * GET /weekly-lottery/year/{year} — one year of history.
     *
     * The year may arrive as Buddhist Era or Gregorian; the shared calendar
     * service decides which and bounds-checks the result. An unacceptable year
     * renders the NO_PUBLIC_DATA state, not a 500 and not an empty success
     * page.
     */
    public function year(Request $request, string $year): View
    {
        // Digits only, at most four. Checked before any cast, so a 900-digit
        // "year" is refused by a regex rather than by the database.
        if (preg_match('/^[0-9]{1,4}$/', $year) !== 1) {
            return $this->renderEmptyYear($year, 'INVALID_QUERY');
        }

        $gregorian = $this->dates->normaliseYearInput((int) $year);

        if ($gregorian === null) {
            return $this->renderEmptyYear($year, 'NO_PUBLIC_DATA');
        }

        $history = $this->history->historyForYear($gregorian, $this->boundedPage($request));
        $label = $this->dates->yearLabel($gregorian, $this->locale());

        return view('weekly-lottery.index', $this->pageData([
            'current' => null,
            'recent' => [],
            'years' => $this->history->availableYears(),
            'active_year' => $gregorian,
            'history' => $history,
            'search' => null,
            'meta' => $this->meta(
                trans('weekly_lottery.meta_title').' — '.$label,
                (string) trans('weekly_lottery.meta_description_year', ['year' => $label]),
                '/weekly-lottery/year/'.$gregorian,
                (bool) config('weekly_lottery.seo.index_year_pages', true)
                    && ($history['status'] ?? '') === 'RESULT_FOUND',
            ),
        ]));
    }

    /**
     * GET /weekly-lottery/search — typed number or date lookup.
     *
     * Rate limited. Never indexed. Never cached.
     */
    public function search(Request $request): View
    {
        $validated = $request->validate([
            // The type is REQUIRED when a term is given and is checked against
            // a closed whitelist. Length is never used to guess it.
            'type' => ['nullable', 'string', 'in:'.implode(',', $this->search->searchTypes())],
            'term' => ['nullable', 'string', 'max:'.$this->search->maxTermLength()],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);

        $type = isset($validated['type']) && $validated['type'] !== '' ? (string) $validated['type'] : null;
        $term = isset($validated['term']) ? trim((string) $validated['term']) : '';
        $page = isset($validated['page']) ? (int) $validated['page'] : 1;

        $outcome = null;

        if ($term !== '') {
            // No type with a term is an incomplete request, not a licence to
            // guess which field the visitor meant.
            $outcome = $type === null
                ? $this->search->search('', $term, $page)
                : $this->search->search($type, $term, $page);
        }

        return view('weekly-lottery.index', $this->pageData([
            'current' => null,
            'recent' => [],
            'years' => $this->history->availableYears(),
            'active_year' => null,
            'history' => null,
            'search' => $outcome,
            'meta' => $this->meta(
                (string) trans('weekly_lottery.meta_title_search'),
                (string) trans('weekly_lottery.meta_description'),
                '/weekly-lottery/search',
                // Search pages are never indexed: they are query dependent and
                // indexing them would invite crawlers to walk the number space.
                false,
            ),
        ]));
    }

    /**
     * Shared view payload.
     *
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
                'index' => route('weekly-lottery.index'),
                'search' => route('weekly-lottery.search'),
            ],
        ]);
    }

    private function renderEmptyYear(string $requested, string $status): View
    {
        $safeLabel = preg_match('/^[0-9]{1,4}$/', $requested) === 1 ? $requested : '';

        return view('weekly-lottery.index', $this->pageData([
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
            'meta' => $this->meta(
                trim(trans('weekly_lottery.meta_title').($safeLabel !== '' ? ' — '.$safeLabel : '')),
                (string) trans('weekly_lottery.meta_description'),
                '/weekly-lottery',
                false,
            ),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(string $title, string $description, string $path, bool $indexable): array
    {
        // Absolute canonical, built from the application URL. Never from the
        // request host, which a proxy or an attacker can set.
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

    private function locale(): string
    {
        return (string) app()->getLocale();
    }

    private function isThai(): bool
    {
        return str_starts_with(strtolower($this->locale()), 'th');
    }
}
