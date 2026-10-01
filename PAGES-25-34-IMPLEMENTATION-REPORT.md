# Pages 25–34 Implementation Report

## Completion status

Pages 25–34 are implemented in the requested order as independent public page routes. Weekly reads the existing Weekly result, history, search, calendar, provenance, and purchase architecture. Mega remains in the existing `/bingo-lottery` route family and reads the existing Bingo/Mega projection architecture. No duplicate lottery, API, search, wallet, ledger, or purchase architecture was created.

## Page map

| Page | Product | Address | Route name | Data/capability state |
|---:|---|---|---|---|
| 25|Weekly Lottery — Draw Detail|/weekly-lottery/draw/{draw}|weekly-lottery.draw|Canonical result projection; unavailable references render the existing translated empty state.|
| 26|Weekly Lottery — Latest Result|/weekly-lottery/latest|weekly-lottery.latest|Canonical current-result selection.|
| 27|Weekly Lottery — Historical Results|/weekly-lottery/history|weekly-lottery.history|Latest data-backed year with bounded server pagination; no years means NO_PUBLIC_DATA.|
| 28|Weekly Lottery — Year Archive|/weekly-lottery/archive/{year}|weekly-lottery.archive|Delegates to the existing bounded year action; /weekly-lottery/year/{year} remains canonical.|
| 29|Weekly Lottery — Result Detail|/weekly-lottery/result/{draw}|weekly-lottery.result|Canonical result projection; no duplicate result service.|
| 30|Mega Lottery|/bingo-lottery|bingo-lottery.index|Replaced fabricated speed-round landing content with source-driven projection/history/search.|
| 31|Mega Lottery — Buy / Ticket Selection|/bingo-lottery/buy|bingo-lottery.buy|NOT_CONFIGURED; no price, ticket, wallet, reservation, ledger, or purchase action is exposed.|
| 32|Mega Lottery — Draw Detail|/bingo-lottery/draw/{draw}|bingo-lottery.draw|Canonical Bingo/Mega result projection.|
| 33|Mega Lottery — Latest Result|/bingo-lottery/latest|bingo-lottery.latest|Canonical current-result selection.|
| 34|Mega Lottery — Historical Results|/bingo-lottery/history|bingo-lottery.history|Latest data-backed year with bounded server pagination; no years means NO_PUBLIC_DATA.|

## Data, provenance, and security decisions

- Result numbers, draw dates, publication state, provenance, integrity, available years, and history rows are rendered only from the existing backend projections and history services.
- String-preserving Mega fields remain `first_6_mega`, `three_mega`, and `two_mega`; views do not cast or calculate result values.
- Missing or unavailable data continues to render the established translated empty/status vocabulary rather than zeroes or sample values.
- Provenance uses the existing public whitelist. No credentials, provider secrets, private metadata, internal IDs, wallet IDs, ledger IDs, or operator notes are added to a view.
- Mega purchase is intentionally fail-closed as `NOT_CONFIGURED`; no purchase contract was inferred from Weekly, Bingo marketing markup, or legacy behavior.
- The old `/bingo-lottery.php` redirect remains handled by `LegacyRedirectController`; no legacy compatibility route was removed.
- English and Thai Mega translation files have matching key order and nested key structures.

## Validation executed

- `node --version`: executed successfully, Node.js `v20.20.2` was available.
- `node --check resources/js/bingo-lottery.js`: executed successfully.
- `node --check resources/js/weekly-lottery.js`: executed successfully.
- Translation parity and forbidden-marker checks: executed successfully with Python.
- `php -l`, PHPUnit, Laravel route compilation, Blade compilation, database-backed feature tests, and browser checks: not executed because PHP is unavailable (`php: command not found`) and `vendor/bin/phpunit` is unavailable.
- `npm run build`: attempted but not executed successfully because the Vite binary is unavailable (`vite: not found`).
## Complete contents of every file created or modified for Pages 25–34

### `app/Http/Controllers/WeeklyLotteryController.php`
# TYPE: PHP
# PURPOSE: Canonical Weekly controller with Pages 25–29 actions while preserving existing result, search, and legacy-compatible actions.

```php
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

        return view('weekly-lottery.draw-detail', $this->pageData([
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

        return view('weekly-lottery.year', $this->pageData([
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
     * Page 25: independently addressable Weekly draw detail surface.
     */
    public function drawDetail(Request $request, string $draw): View
    {
        unset($request);

        $projection = $this->results->resultForReference($draw);

        return view('weekly-lottery.draw-detail', $this->pageData([
            'current' => $projection,
            'years' => $this->history->availableYears(),
            'active_year' => null,
            'meta' => $this->meta(
                (string) trans('weekly_lottery.detail_heading').' — '.trans('weekly_lottery.meta_title'),
                (string) trans('weekly_lottery.meta_description'),
                '/weekly-lottery/draw/'.$draw,
                (bool) ($projection['available'] ?? false) && (bool) ($projection['has_numbers'] ?? true),
            ),
        ]));
    }

    /**
     * Page 26: latest result selected by the canonical result service.
     */
    public function latestResult(Request $request): View
    {
        unset($request);

        $current = $this->results->currentResult();
        $reference = is_array($current['draw'] ?? null) && isset($current['draw']['reference'])
            ? (string) $current['draw']['reference']
            : null;

        return view('weekly-lottery.latest-result', $this->pageData([
            'current' => $current,
            'recent' => $this->history->recentDraws($reference),
            'years' => $this->history->availableYears(),
            'active_year' => null,
            'meta' => $this->meta(
                (string) trans('weekly_lottery.meta_title').' — '.trans('weekly_lottery.current_result_heading'),
                (string) trans('weekly_lottery.meta_description'),
                '/weekly-lottery/latest',
                (bool) ($current['available'] ?? false) && (bool) ($current['has_numbers'] ?? true),
            ),
        ]));
    }

    /**
     * Page 27: server-backed Weekly historical results.
     */
    public function historicalResults(Request $request): View
    {
        $latestYear = $this->history->latestYear();
        $history = $latestYear === null
            ? null
            : $this->history->historyForYear($latestYear, $this->boundedPage($request));

        return view('weekly-lottery.history', $this->pageData([
            'current' => null,
            'recent' => [],
            'years' => $this->history->availableYears(),
            'active_year' => $latestYear,
            'history' => $history,
            'search' => null,
            'meta' => $this->meta(
                (string) trans('weekly_lottery.meta_title').' — '.trans('weekly_lottery.history_heading'),
                (string) trans('weekly_lottery.meta_description'),
                '/weekly-lottery/history',
                $history !== null && ($history['status'] ?? '') === 'RESULT_FOUND',
            ),
        ]));
    }

    /**
     * Page 29: separate result-detail route using the same projection as the
     * existing canonical /weekly-lottery/{draw} surface.
     */
    public function resultDetail(Request $request, string $draw): View
    {
        unset($request);

        $projection = $this->results->resultForReference($draw);

        return view('weekly-lottery.result-detail', $this->pageData([
            'current' => $projection,
            'years' => $this->history->availableYears(),
            'active_year' => null,
            'meta' => $this->meta(
                (string) trans('weekly_lottery.detail_heading').' — '.trans('weekly_lottery.meta_title'),
                (string) trans('weekly_lottery.meta_description'),
                '/weekly-lottery/result/'.$draw,
                (bool) ($projection['available'] ?? false) && (bool) ($projection['has_numbers'] ?? true),
            ),
        ]));
    }

    /**
     * Page 28 compatibility entry point. The existing /year/{year} route
     * remains the canonical route family and is not renamed.
     */
    public function yearArchive(Request $request, string $year): View
    {
        return $this->year($request, $year);
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

        return view('weekly-lottery.year', $this->pageData([
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
```

### `app/Http/Controllers/BingoLotteryController.php`
# TYPE: PHP
# PURPOSE: Canonical Mega/Bingo-family controller with Pages 30–34 actions and fail-closed purchase handling.

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Lottery\BingoLotteryDateService;
use App\Services\Lottery\BingoLotteryHistoryService;
use App\Services\Lottery\BingoLotteryResultService;
use App\Services\Lottery\BingoLotterySearchService;
use App\Services\Lottery\BingoLotterySourceService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Public Mega Lottery result pages (PROMPT 8).
 *
 * THIN. Every method validates the shape and the BOUNDS of its input, then
 * hands off to a service. There is no query, no date arithmetic, no source
 * reasoning and no caching decision in this file, so none of those exists in
 * two places.
 *
 * ANONYMOUS. All four routes are public GETs. /bingo-lottery/search carries
 * the 'bingo-result-search' limiter, because a public lookup over a
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
final class BingoLotteryController
{
    public function __construct(
        private readonly BingoLotteryResultService $results,
        private readonly BingoLotteryHistoryService $history,
        private readonly BingoLotterySearchService $search,
        private readonly BingoLotterySourceService $sources,
        private readonly BingoLotteryDateService $dates,
    ) {}

    /**
     * GET /bingo-lottery — current result plus recent draws.
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

        return view('bingo-lottery.index', $this->pageData([
            'current' => $current,
            'recent' => $this->history->recentDraws($reference),
            'years' => $this->history->availableYears(),
            'active_year' => $latestYear,
            'history' => $latestYear === null
                ? null
                : $this->history->historyForYear($latestYear, 1),
            'search' => null,
            'meta' => $this->meta(
                (string) trans('bingo_lottery.meta_title'),
                (string) trans('bingo_lottery.meta_description'),
                '/bingo-lottery',
                true,
            ),
        ]));
    }

    /**
     * GET /bingo-lottery/{draw} — one draw in detail.
     *
     * The route parameter is constrained to [A-Za-z0-9\-]{1,40} in
     * routes/web.php and re-checked inside the service, so a crafted
     * reference never reaches a query as anything but a bound string.
     */
    public function show(Request $request, string $draw): View
    {
        unset($request);

        $projection = $this->results->resultForReference($draw);

        $title = (string) trans('bingo_lottery.meta_title');

        if (($projection['available'] ?? false) === true && is_array($projection['draw'] ?? null)) {
            $date = $projection['draw']['date'] ?? [];
            $label = is_array($date)
                ? (string) ($this->isThai() ? ($date['display_th'] ?? '') : ($date['display_en'] ?? ''))
                : '';

            if ($label !== '') {
                $title .= ' — '.$label;
            }
        }

        return view('bingo-lottery.draw-detail', $this->pageData([
            'current' => $projection,
            'recent' => [],
            'years' => $this->history->availableYears(),
            'active_year' => null,
            'history' => null,
            'search' => null,
            'meta' => $this->meta(
                $title,
                (string) trans('bingo_lottery.meta_description'),
                '/bingo-lottery/'.$draw,
                ($projection['available'] ?? false) === true,
            ),
        ]));
    }

    /**
     * GET /bingo-lottery/year/{year} — one year of history.
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

        return view('bingo-lottery.year', $this->pageData([
            'current' => null,
            'recent' => [],
            'years' => $this->history->availableYears(),
            'active_year' => $gregorian,
            'history' => $history,
            'search' => null,
            'meta' => $this->meta(
                trans('bingo_lottery.meta_title').' — '.$label,
                (string) trans('bingo_lottery.meta_description_year', ['year' => $label]),
                '/bingo-lottery/year/'.$gregorian,
                (bool) config('bingo_lottery.seo.index_year_pages', true)
                    && ($history['status'] ?? '') === 'RESULT_FOUND',
            ),
        ]));
    }

    /**
     * GET /bingo-lottery/search — typed number or date lookup.
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

        return view('bingo-lottery.index', $this->pageData([
            'current' => null,
            'recent' => [],
            'years' => $this->history->availableYears(),
            'active_year' => null,
            'history' => null,
            'search' => $outcome,
            'meta' => $this->meta(
                (string) trans('bingo_lottery.meta_title_search'),
                (string) trans('bingo_lottery.meta_description'),
                '/bingo-lottery/search',
                // Search pages are never indexed: they are query dependent and
                // indexing them would invite crawlers to walk the number space.
                false,
            ),
        ]));
    }

    /**
     * Page 32: independently addressable Mega draw detail.
     */
    public function drawDetail(Request $request, string $draw): View
    {
        unset($request);

        $projection = $this->results->resultForReference($draw);

        return view('bingo-lottery.draw-detail', $this->pageData([
            'current' => $projection,
            'years' => $this->history->availableYears(),
            'active_year' => null,
            'meta' => $this->meta(
                (string) trans('bingo_lottery.detail_heading').' — '.trans('bingo_lottery.meta_title'),
                (string) trans('bingo_lottery.meta_description'),
                '/bingo-lottery/draw/'.$draw,
                (bool) ($projection['available'] ?? false) && (bool) ($projection['has_numbers'] ?? true),
            ),
        ]));
    }

    /**
     * Page 33: latest Mega result from the canonical Bingo result service.
     */
    public function latestResult(Request $request): View
    {
        unset($request);

        $current = $this->results->currentResult();
        $reference = is_array($current['draw'] ?? null) && isset($current['draw']['reference'])
            ? (string) $current['draw']['reference']
            : null;

        return view('bingo-lottery.latest-result', $this->pageData([
            'current' => $current,
            'recent' => $this->history->recentDraws($reference),
            'years' => $this->history->availableYears(),
            'active_year' => null,
            'meta' => $this->meta(
                (string) trans('bingo_lottery.meta_title').' — '.trans('bingo_lottery.current_result_heading'),
                (string) trans('bingo_lottery.meta_description'),
                '/bingo-lottery/latest',
                (bool) ($current['available'] ?? false) && (bool) ($current['has_numbers'] ?? true),
            ),
        ]));
    }

    /**
     * Page 34: server-backed Mega historical results.
     */
    public function historicalResults(Request $request): View
    {
        $latestYear = $this->history->latestYear();
        $history = $latestYear === null
            ? null
            : $this->history->historyForYear($latestYear, $this->boundedPage($request));

        return view('bingo-lottery.history', $this->pageData([
            'current' => null,
            'recent' => [],
            'years' => $this->history->availableYears(),
            'active_year' => $latestYear,
            'history' => $history,
            'search' => null,
            'meta' => $this->meta(
                (string) trans('bingo_lottery.meta_title').' — '.trans('bingo_lottery.history_heading'),
                (string) trans('bingo_lottery.meta_description'),
                '/bingo-lottery/history',
                $history !== null && ($history['status'] ?? '') === 'RESULT_FOUND',
            ),
        ]));
    }

    /**
     * Page 29-equivalent Mega result detail; the existing show route remains
     * the compatibility surface.
     */
    public function resultDetail(Request $request, string $draw): View
    {
        unset($request);

        $projection = $this->results->resultForReference($draw);

        return view('bingo-lottery.result-detail', $this->pageData([
            'current' => $projection,
            'years' => $this->history->availableYears(),
            'active_year' => null,
            'meta' => $this->meta(
                (string) trans('bingo_lottery.detail_heading').' — '.trans('bingo_lottery.meta_title'),
                (string) trans('bingo_lottery.meta_description'),
                '/bingo-lottery/result/'.$draw,
                (bool) ($projection['available'] ?? false) && (bool) ($projection['has_numbers'] ?? true),
            ),
        ]));
    }

    /**
     * Page 31 purchase entry. No product-specific Mega purchase contract was
     * found, so the view is intentionally fail-closed.
     */
    public function buy(Request $request): View
    {
        unset($request);

        return view('bingo-lottery.buy', [
            'product_key' => 'bingo_lottery',
            'product_title' => (string) trans('bingo_lottery.public_name'),
            'product_description' => (string) trans('bingo_lottery.intro'),
            'purchase_state' => 'NOT_CONFIGURED',
            'back_url' => route('bingo-lottery.index'),
            'canonical' => rtrim((string) config('app.url'), '/').route('bingo-lottery.buy', [], false),
        ]);
    }

    /**
     * Page 28-equivalent compatibility entry point. The existing
     * /bingo-lottery/year/{year} route remains canonical.
     */
    public function yearArchive(Request $request, string $year): View
    {
        return $this->year($request, $year);
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
                'index' => route('bingo-lottery.index'),
                'search' => route('bingo-lottery.search'),
            ],
        ]);
    }

    private function renderEmptyYear(string $requested, string $status): View
    {
        $safeLabel = preg_match('/^[0-9]{1,4}$/', $requested) === 1 ? $requested : '';

        return view('bingo-lottery.year', $this->pageData([
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
                trim(trans('bingo_lottery.meta_title').($safeLabel !== '' ? ' — '.$safeLabel : '')),
                (string) trans('bingo_lottery.meta_description'),
                '/bingo-lottery',
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
```

### `routes/web.php`
# TYPE: PHP routes
# PURPOSE: Independent Weekly and Mega page routes, canonical wildcard ordering, and preserved route families.

```php
<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\LottoFinExecutiveDashboardController;
use App\Http\Controllers\GloResultsPageController;
use App\Http\Controllers\Player\PlayerSecuritySettingsController;
use App\Http\Controllers\Player\PlayerDashboardController;
use App\Http\Controllers\Player\PlayerProfilePortalController;
use App\Http\Controllers\Player\PlayerSettingsPortalController;
use App\Http\Controllers\Player\LotteryHistoryPortalController;
use App\Http\Controllers\Wallet\WalletManagementPageController;
use App\Http\Controllers\Payment\DepositMethodsPageController;
use App\Http\Controllers\Payment\WithdrawalMethodsPageController;
use App\Http\Controllers\Betting\ThaiLotteryBettingController;
use App\Http\Controllers\AccountGradeController;
use App\Http\Controllers\AccountVerificationController;
use App\Http\Controllers\BingoLotteryController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LottoDiscountController;
use App\Http\Controllers\LotteryHubController;
use App\Http\Controllers\LotteryPurchasePageController;
use App\Http\Controllers\LegacyRedirectController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\NationalLotteryController;
use App\Http\Controllers\PcsoLotteryController;
use App\Http\Controllers\PrizeVerificationController;
use App\Http\Controllers\PublicAccountInfoController;
use App\Http\Controllers\PublicPagesController;
use App\Http\Controllers\PublicServicePagesController;
use App\Http\Controllers\ResultsController;
use App\Http\Controllers\Auth\MemberAuthController;
use App\Http\Controllers\Verification\AccountVerificationController as MemberAccountVerificationController;
use App\Http\Controllers\Web\BetPurchaseController;
use App\Http\Controllers\Web\PaymentCallbackController;
use App\Http\Controllers\Web\PlayerWebController;
use App\Http\Controllers\WeeklyLotteryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| The session-authenticated player web app. Laravel's default web middleware group is
| applied automatically (CSRF, session, cookies), plus the global security headers and
| correlation id middleware registered in bootstrap/app.php.
|
| Every route name here is what the Blade views and the player experience tests already
| reference, so the names are part of the contract:
|   login, login.attempt, register, register.attempt, logout,
|   player.dashboard, player.draws, player.draws.detail, player.bet, player.bets,
|   player.wallet, player.deposit, player.deposit.store, player.withdraw,
|   player.withdraw.store, player.profile, player.profile.update, player.profile.password,
|   player.profile.limits, player.bets.purchase, player.password.update, player.limits.update
|
*/

/*
| Operational endpoints. `/up` is the framework liveness probe registered in
| bootstrap/app.php; the structured health trio and the Prometheus metrics export live
| here against the same HealthController / MetricsController that the observability
| services back.
*/
// P0: /metrics is operator-only telemetry — never financial-public.
Route::middleware(['auth', 'can:access-metrics'])->group(function (): void {
    Route::get('/metrics', [MetricsController::class, 'metrics'])->name('metrics');
});
Route::get('/up/health', [HealthController::class, 'health'])->name('health');
Route::get('/up/ready', [HealthController::class, 'ready'])->name('health.ready');
Route::get('/up/live', [HealthController::class, 'live'])->name('health.live');
Route::get('/health', [HealthController::class, 'health'])->name('health.canonical');
Route::get('/ready', [HealthController::class, 'ready'])->name('health.ready.canonical');
Route::get('/live', [HealthController::class, 'live'])->name('health.live.canonical');

Route::middleware('guest')->group(function (): void {
    // PROMPT 3: the member auth surface (login / registration /
    // password recovery) is served by MemberAuthController — thin
    // orchestration over LoginService / RegistrationService /
    // PasswordResetService (+ the server-authoritative CaptchaService
    // gate). Same route names as before, so every existing link,
    // redirect and test keeps resolving.
    Route::get('/login', [MemberAuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [MemberAuthController::class, 'login'])->name('login.attempt')->middleware('throttle:login');
    Route::get('/register', [MemberAuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [MemberAuthController::class, 'register'])->name('register.attempt')->middleware('throttle:login');

    // Password recovery: account no./email + CAPTCHA request, then the
    // token-gated new-password form. Throttled on both POSTs.
    Route::get('/forgot-password', [MemberAuthController::class, 'showForgotPassword'])->name('password.request');
    Route::post('/forgot-password', [MemberAuthController::class, 'requestReset'])
        ->middleware('throttle:password-reset')
        ->name('password.request.attempt');
    Route::get('/reset-password/{token}', [MemberAuthController::class, 'showResetForm'])->name('password.reset');
    Route::post('/reset-password', [MemberAuthController::class, 'resetPassword'])
        ->middleware('throttle:password-reset')
        ->name('password.reset.attempt');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [MemberAuthController::class, 'logout'])->name('logout');

    Route::get('/dashboard', [PlayerWebController::class, 'dashboard'])->name('player.dashboard');
    Route::get('/draws', [PlayerWebController::class, 'draws'])->name('player.draws');
    Route::get('/draws/{id}', [PlayerWebController::class, 'drawDetail'])->name('player.draws.detail');

    Route::get('/bet', [PlayerWebController::class, 'betSlip'])->name('player.bet');
    Route::post('/bet/purchase', [BetPurchaseController::class, 'store'])
        ->middleware('throttle:bet')
        ->name('player.bets.purchase');
    Route::post('/player/bets/purchase', [BetPurchaseController::class, 'store'])
        ->middleware('throttle:bet')
        ->name('player.bets.purchase.alias');
    Route::get('/bets', [PlayerWebController::class, 'bets'])->name('player.bets');

    Route::get('/wallet', [PlayerWebController::class, 'wallet'])->name('player.wallet');

    Route::get('/deposit', [PlayerWebController::class, 'deposit'])->name('player.deposit');
    Route::post('/deposit', [PlayerWebController::class, 'storeDeposit'])
        ->middleware('throttle:deposit')
        ->name('player.deposit.store');

    Route::get('/withdraw', [PlayerWebController::class, 'withdraw'])->name('player.withdraw');
    Route::post('/withdraw', [PlayerWebController::class, 'storeWithdraw'])
        ->middleware('throttle:withdrawal')
        ->name('player.withdraw.store');

    Route::get('/profile', [PlayerWebController::class, 'profile'])->name('player.profile');
    Route::put('/profile', [PlayerWebController::class, 'updateProfile'])->name('player.profile.update');
    Route::put('/profile/password', [PlayerWebController::class, 'updatePassword'])->name('player.password.update');
    Route::put('/player/profile/password', [PlayerWebController::class, 'updatePassword'])->name('player.profile.password');
    Route::put('/profile/limits', [PlayerWebController::class, 'updateLimits'])->name('player.limits.update');
    Route::put('/player/profile/limits', [PlayerWebController::class, 'updateLimits'])->name('player.profile.limits');
});

/*
|---------------------------------------------------------------------------
| Account services (PROMPT 3): verification + grade — authenticated only
|---------------------------------------------------------------------------
| Ownership is always the session user. Rate limits: account-verification /
| account-grade (registered in AppServiceProvider).
*/
Route::middleware('auth')->group(function (): void {
    // PROMPT 3: the member Account Verify page is served by the
    // Verification controller (policy-authorized, self-scoped, the
    // immutable submission aggregate behind it). The reviewer decision
    // route is policy-walled (AccountVerificationPolicy::decide).
    Route::get('/account/verification', [MemberAccountVerificationController::class, 'show'])
        ->name('account.verification');
    Route::post('/account/verification', [MemberAccountVerificationController::class, 'submit'])
        ->middleware('throttle:account-verification')
        ->name('account.verification.submit');
    Route::get('/account/verification/document/{document}', [MemberAccountVerificationController::class, 'download'])
        ->middleware('throttle:account-verification')
        ->name('account.verification.document');
    Route::post('/account/verification/{verification}/decision', [MemberAccountVerificationController::class, 'decide'])
        ->middleware('throttle:account-verification')
        ->name('account.verification.decide');

    Route::get('/account/grade', [AccountGradeController::class, 'show'])
        ->middleware('throttle:account-grade')
        ->name('account.grade');
    Route::get('/account/grade/history', [AccountGradeController::class, 'history'])
        ->middleware('throttle:account-grade')
        ->name('account.grade.history');
    Route::post('/account/grade/refresh', [AccountGradeController::class, 'refresh'])
        ->middleware('throttle:account-grade')
        ->name('account.grade.refresh');
});

/*
|--------------------------------------------------------------------------
| Public Home + supporting public pages (anonymous by design)
|--------------------------------------------------------------------------
| Results are served from the verified projection only; fixture datasets are
| labeled FIXTURE_ONLY and are never called official.
*/

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/dashboard', [PlayerDashboardController::class, 'index'])->name('dashboard');
Route::get('/player/dashboard', [PlayerDashboardController::class, 'index'])->name('player.dashboard');
Route::get('/wallet', [WalletManagementPageController::class, 'index'])->name('wallet.index');
Route::get('/player/wallet', [WalletManagementPageController::class, 'index'])->name('player.wallet');
Route::get('/deposit', [DepositMethodsPageController::class, 'index'])->name('deposit.index');
Route::get('/wallet/deposit', [DepositMethodsPageController::class, 'index'])->name('wallet.deposit');
Route::get('/withdrawal', [WithdrawalMethodsPageController::class, 'index'])->name('withdrawal.index');
Route::get('/wallet/withdrawal', [WithdrawalMethodsPageController::class, 'index'])->name('wallet.withdrawal');
Route::get('/betting', [ThaiLotteryBettingController::class, 'index'])->name('betting.index');
Route::get('/lotto/betting', [ThaiLotteryBettingController::class, 'index'])->name('lotto.betting');
Route::get('/results', [GloResultsPageController::class, 'index'])->name('results.index');
Route::get('/player/security', [PlayerSecuritySettingsController::class, 'index'])->name('player.security');
Route::get('/player/settings', [PlayerSecuritySettingsController::class, 'index'])->name('player.settings');
Route::get('/settings', [PlayerSettingsPortalController::class, 'index'])->name('settings.index');
Route::get('/member/settings', [PlayerSettingsPortalController::class, 'index'])->name('member.settings');
Route::get('/player/settings-portal', [PlayerSettingsPortalController::class, 'index'])->name('player.settings.portal');
Route::get('/profile', [PlayerProfilePortalController::class, 'index'])->name('profile.index');
Route::get('/member/profile', [PlayerProfilePortalController::class, 'index'])->name('member.profile');
Route::get('/player/profile-portal', [PlayerProfilePortalController::class, 'index'])->name('player.profile.portal');
Route::get('/history', [LotteryHistoryPortalController::class, 'index'])->name('history.index');
Route::get('/member/history', [LotteryHistoryPortalController::class, 'index'])->name('member.history');
Route::get('/player/history-portal', [LotteryHistoryPortalController::class, 'index'])->name('player.history.portal');
Route::get('/results/search', [ResultsController::class, 'search'])->name('results.search');

// Public ticket check UI (primary UX; the JSON API remains at /api/v1/glo/results/check/{n}).
Route::get('/check', [HomeController::class, 'checkForm'])->name('ticket-check');
Route::post('/check', [HomeController::class, 'checkSubmit'])
    ->middleware('throttle:home-check')
    ->name('ticket-check.submit');

// Public sales-point search UI (uses existing GloSalesPointService).
Route::get('/sales-points', [HomeController::class, 'salesPoints'])->name('sales-points');

// Public informational + legal pages (versioned Terms from config/legal.php).
// public.legal = PublicLegalHeaders middleware: safe guest GET cache only.
Route::get('/about', [PublicPagesController::class, 'about'])
    ->middleware('public.legal')
    ->name('about');
Route::get('/vision', [PublicPagesController::class, 'vision'])
    ->middleware('public.legal')
    ->name('vision');
Route::get('/terms', [PublicPagesController::class, 'terms'])
    ->middleware('public.legal')
    ->name('terms');

// Public Fees (PROMPT 3) — anonymous, config-driven, no user-specific fees.
Route::get('/fees', [PublicPagesController::class, 'fees'])
    ->middleware('public.legal')
    ->name('fees');
Route::get('/our-fees', [PublicPagesController::class, 'fees'])
    ->middleware('public.legal')
    ->name('our-fees');

// Public Prize Verification (PROMPT 4) — anonymous ticket / result checker.
Route::get('/prize-verification', [\App\Http\Controllers\PublicPrizeVerificationController::class, 'index'])
    ->middleware('public.legal')
    ->name('prize-verification');
Route::post('/prize-verification', [\App\Http\Controllers\PublicPrizeVerificationController::class, 'verifyApi'])
    ->middleware('throttle:ticket-verification')
    ->name('prize-verification.verify');
Route::post('/prize-verification', [\App\Http\Controllers\PublicPrizeVerificationController::class, 'verifyApi'])
    ->middleware('throttle:ticket-verification')
    ->name('prize-verification.submit');

// Public Discount Rules (PROMPT 4) — anonymous product/game matrix.
Route::get('/discounts', [\App\Http\Controllers\PublicLottoDiscountController::class, 'index'])
    ->middleware('public.legal')
    ->name('discounts');
Route::get('/lotto-discount', [\App\Http\Controllers\PublicLottoDiscountController::class, 'index'])
    ->middleware('public.legal')
    ->name('lotto-discount');

// Public How to Play Guide
Route::get('/how-to-play', [\App\Http\Controllers\PublicHowToPlayController::class, 'index'])
    ->middleware('public.legal')
    ->name('how-to-play');

// Public FAQ / Knowledge Base
Route::get('/faq', [\App\Http\Controllers\PublicFaqController::class, 'index'])
    ->middleware('public.legal')
    ->name('faq');

/*
|--------------------------------------------------------------------------
| PROMPT 5: public National Lottery result surface
|--------------------------------------------------------------------------
|
| A SEPARATE PRODUCT LANE. These four routes serve national_lottery_* data and
| nothing else: not GLO L6/N3, not an operator market, not a lottery provider
| that has not published. They read; nothing here writes.
|
| ORDER IS LOAD-BEARING. /search and /year/{year} are declared BEFORE
| /{draw}. Reversed, the wildcard would match the literal string "search" and
| a visitor asking to search would get a draw-not-found page for a draw named
| "search".
|
| PARAMETER PATTERNS ARE HARD BOUNDARIES. {year} is at most four decimal
| digits and {draw} is at most forty characters of an explicit alphabet, so a
| hostile URL is refused by the router before a controller, a validator or a
| query is ever reached.
|
| /search carries throttle:national-result-search (registered in
| AppServiceProvider from config('national_lottery.rate_limit')): IP per
| minute, IP per hour, and a hashed query fingerprint per minute. robots.txt
| asks crawlers to stay out of the same path, but that is a request - this
| limiter is the control.
|
| public.legal = PublicLegalHeaders: short guest-GET cache headers on the
| listed cacheable paths only. The search route is deliberately outside that
| list, so a query-dependent response is never cached at the edge.
*/
Route::get('/lotteries', [LotteryHubController::class, 'index'])
    ->middleware('public.legal')
    ->name('lotteries.index');

Route::get('/national-lottery', [NationalLotteryController::class, 'index'])
    ->middleware('public.legal')
    ->name('national-lottery.index');

Route::get('/national-lottery/buy', [LotteryPurchasePageController::class, 'national'])
    ->middleware('public.legal')
    ->name('national-lottery.buy');

Route::get('/national-lottery/latest', [NationalLotteryController::class, 'latestResult'])
    ->middleware('public.legal')
    ->name('national-lottery.latest');

Route::get('/national-lottery/history', [NationalLotteryController::class, 'historicalResults'])
    ->middleware('public.legal')
    ->name('national-lottery.history');

Route::get('/national-lottery/draw/{draw}', [NationalLotteryController::class, 'drawDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('national-lottery.draw-detail');

Route::get('/national-lottery/result/{draw}', [NationalLotteryController::class, 'resultDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('national-lottery.result-detail');

Route::get('/national-lottery/search', [NationalLotteryController::class, 'search'])
    ->middleware('throttle:national-result-search')
    ->name('national-lottery.search');

Route::get('/national-lottery/year/{year}', [NationalLotteryController::class, 'year'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('national-lottery.year');

Route::get('/national-lottery/archive/{year}', [NationalLotteryController::class, 'year'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('national-lottery.year-archive');

Route::get('/national-lottery/{draw}', [NationalLotteryController::class, 'show'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('national-lottery.show');

/*
|--------------------------------------------------------------------------
| PROMPT 6: public Weekly Lottery result surface
|--------------------------------------------------------------------------
|
| A SEPARATE PRODUCT LANE. These canonical and page-specific routes serve
| weekly_lottery_* data and nothing else: not GLO L6/N3, not the National lane,
| not an operator market. They read; nothing here writes.
|
| ORDER IS LOAD-BEARING. /search and /year/{year} are declared BEFORE
| /{draw}. Reversed, the wildcard would match the literal string "search" and
| a visitor asking to search would get a draw-not-found page for a draw named
| "search".
|
| PARAMETER PATTERNS ARE HARD BOUNDARIES. {year} is at most four decimal
| digits and {draw} is at most forty characters of an explicit alphabet, so a
| hostile URL is refused by the router before a controller, a validator or a
| query is ever reached.
|
| /search carries throttle:weekly-result-search (registered in
| AppServiceProvider from config('weekly_lottery.rate_limit')): IP per minute,
| IP per hour, and a hashed query fingerprint per minute. A public lookup over
| a 1,000,000-value space is an enumeration oracle without it.
|
| public.legal = PublicLegalHeaders: short guest-GET cache headers on the
| listed cacheable paths only. The search route is deliberately outside that
| list, so a query-dependent response is never cached at the edge.
*/
Route::get('/weekly-lottery', [WeeklyLotteryController::class, 'index'])
    ->middleware('public.legal')
    ->name('weekly-lottery.index');

Route::get('/weekly-lottery/buy', [LotteryPurchasePageController::class, 'weekly'])
    ->middleware('public.legal')
    ->name('weekly-lottery.buy');

Route::get('/weekly-lottery/search', [WeeklyLotteryController::class, 'search'])
    ->middleware('throttle:weekly-result-search')
    ->name('weekly-lottery.search');

Route::get('/weekly-lottery/latest', [WeeklyLotteryController::class, 'latestResult'])
    ->middleware('public.legal')
    ->name('weekly-lottery.latest');

Route::get('/weekly-lottery/history', [WeeklyLotteryController::class, 'historicalResults'])
    ->middleware('public.legal')
    ->name('weekly-lottery.history');

Route::get('/weekly-lottery/archive/{year}', [WeeklyLotteryController::class, 'yearArchive'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('weekly-lottery.archive');

Route::get('/weekly-lottery/draw/{draw}', [WeeklyLotteryController::class, 'drawDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('weekly-lottery.draw');

Route::get('/weekly-lottery/result/{draw}', [WeeklyLotteryController::class, 'resultDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('weekly-lottery.result');

Route::get('/weekly-lottery/year/{year}', [WeeklyLotteryController::class, 'year'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('weekly-lottery.year');

Route::get('/weekly-lottery/{draw}', [WeeklyLotteryController::class, 'show'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('weekly-lottery.show');

/*
|--------------------------------------------------------------------------
| PROMPT 8: public Bingo / Mega Lottery result surface
|--------------------------------------------------------------------------
|
| A SEPARATE PRODUCT LANE. These canonical and page-specific routes serve
| bingo_lottery_* data and nothing else: not GLO L6/N3, not the National lane,
| not the Weekly lane, not an operator market. They read; nothing here writes.
|
| ORDER IS LOAD-BEARING. /search and /year/{year} are declared BEFORE
| /{draw}. Reversed, the wildcard would match the literal string "search" and
| a visitor asking to search would get a draw-not-found page for a draw named
| "search".
|
| /search carries throttle:bingo-result-search (registered in
| AppServiceProvider from config('bingo_lottery.rate_limit')): IP per minute,
| IP per hour, and a hashed query fingerprint per minute. robots.txt asks
| crawlers to stay out of the same path, but that is a request - this limiter
| is the control.
|
*/
Route::get('/bingo-lottery', [BingoLotteryController::class, 'index'])
    ->middleware('public.legal')
    ->name('bingo-lottery.index');

Route::get('/bingo-lottery/search', [BingoLotteryController::class, 'search'])
    ->middleware('throttle:bingo-result-search')
    ->name('bingo-lottery.search');

Route::get('/bingo-lottery/buy', [BingoLotteryController::class, 'buy'])
    ->middleware('public.legal')
    ->name('bingo-lottery.buy');

Route::get('/bingo-lottery/latest', [BingoLotteryController::class, 'latestResult'])
    ->middleware('public.legal')
    ->name('bingo-lottery.latest');

Route::get('/bingo-lottery/history', [BingoLotteryController::class, 'historicalResults'])
    ->middleware('public.legal')
    ->name('bingo-lottery.history');

Route::get('/bingo-lottery/archive/{year}', [BingoLotteryController::class, 'yearArchive'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('bingo-lottery.archive');

Route::get('/bingo-lottery/draw/{draw}', [BingoLotteryController::class, 'drawDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('bingo-lottery.draw');

Route::get('/bingo-lottery/result/{draw}', [BingoLotteryController::class, 'resultDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('bingo-lottery.result');

Route::get('/bingo-lottery/year/{year}', [BingoLotteryController::class, 'year'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('bingo-lottery.year');

Route::get('/bingo-lottery/{draw}', [BingoLotteryController::class, 'show'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('bingo-lottery.show');

/*
|--------------------------------------------------------------------------
| PROMPT 9: public PCSO Lottery result surface
|--------------------------------------------------------------------------
|
| A SEPARATE PRODUCT LANE. Four routes over pcso_lottery_* data: not GLO
| L6/N3, not National, not Weekly, not Mega, not an operator market. They
| read; nothing here writes.
|
| ORDER IS LOAD-BEARING. /search and /year/{year} are declared BEFORE
| /{draw}. Reversed, the wildcard would match the literal string "search" and
| a visitor asking to search would get a draw-not-found page for a draw named
| "search".
|
| The {draw} pattern allows the longer PCSO reference, which carries a draw
| TIME as well as a date (PCSO-20260910-2100) because this lane publishes
| several draws per day.
|
| /search carries throttle:pcso-result-search. robots.txt asks crawlers to
| stay out of the same path, but that is a request - this limiter is the
| control.
|
*/

Route::get('/pcso-lottery', [PcsoLotteryController::class, 'index'])
    ->middleware('public.legal')
    ->name('pcso-lottery.index');

Route::get('/pcso-lottery/search', [PcsoLotteryController::class, 'search'])
    ->middleware('throttle:pcso-result-search')
    ->name('pcso-lottery.search');

Route::get('/pcso-lottery/year/{year}', [PcsoLotteryController::class, 'year'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('pcso-lottery.year');

Route::get('/pcso-lottery/{draw}', [PcsoLotteryController::class, 'show'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('pcso-lottery.show');

// Static pages used by footer/support CTAs when configured.
Route::get('/privacy', [PublicPagesController::class, 'privacy'])->name('privacy');

/*
|--------------------------------------------------------------------------
| PROMPT 10: public Contact / Support centre
|--------------------------------------------------------------------------
|
| The GET route KEEPS ITS NAME. About, both footers, the privacy page and the
| terms page all link to route('contact'), and existing tests assert those
| links resolve. Renaming it to something tidier would have broken five
| surfaces to gain nothing.
|
| The POST carries throttle:contact-submit. A public endpoint that sends mail
| is a relay without one. It is also inside the normal web middleware group,
| so Laravel's CSRF protection applies - deliberately not excluded to make an
| AJAX submission simpler.
|
*/

/*
|--------------------------------------------------------------------------
| Public account-programme explainers
|--------------------------------------------------------------------------
|
| SIGNED-OUT INFORMATION, NOT THE ACCOUNT PAGES. /account/grade and
| /account/verification stay behind auth and show a person their own figures.
| These two show the LADDER and the PROCESS to somebody who has not
| registered and therefore cannot see either.
|
| Separate paths on purpose: relaxing auth on the existing routes would have
| meant one URL answering differently depending on who asked, which is how a
| personal figure eventually renders for a guest.
|
*/

Route::get('/account-grades', [\App\Http\Controllers\PublicGradeController::class, 'index'])
    ->middleware('public.legal')
    ->name('account-grades');

Route::get('/account-grade', [\App\Http\Controllers\PublicGradeController::class, 'index'])
    ->middleware('public.legal')
    ->name('account-grade');

Route::get('/account-verification', [\App\Http\Controllers\PublicVerificationController::class, 'index'])
    ->middleware('public.legal')
    ->name('account-verification');

Route::get('/account-verification-guide', [\App\Http\Controllers\PublicVerificationController::class, 'index'])
    ->middleware('public.legal')
    ->name('account-verification-guide');

Route::get('/contact', [\App\Http\Controllers\ContactController::class, 'show'])
    ->middleware('public.legal')
    ->name('contact');

Route::get('/contact-us', [\App\Http\Controllers\ContactController::class, 'show'])
    ->middleware('public.legal')
    ->name('contact-us');

Route::get('/download', [\App\Http\Controllers\PublicDownloadAppController::class, 'index'])
    ->middleware('public.legal')
    ->name('download');

Route::get('/download-app', [\App\Http\Controllers\PublicDownloadAppController::class, 'index'])
    ->middleware('public.legal')
    ->name('download-app');

Route::get('/app', [\App\Http\Controllers\PublicDownloadAppController::class, 'index'])
    ->middleware('public.legal')
    ->name('app');

// XML sitemap (FINAL AUDIT #15): canonical public URLs only — no auth,
// admin, API, search-form, payment-return or legacy .php duplicates.
// Read-only and cacheable.
Route::get('/sitemap.xml', \App\Http\Controllers\SitemapController::class)
    ->name('sitemap');

/*
|--------------------------------------------------------------------------
| Browser payment-return pages (FINAL AUDIT #2)
|--------------------------------------------------------------------------
|
| Where a gateway drops the player's browser after checkout. PRESENTATION
| ONLY: the landing route is context, the displayed state is always the
| internal payment record (see PaymentCallbackController), and nothing on
| these pages can credit or change money. Paths come from the same
| config/payment.php callback block the gateway drivers build their
| success/cancel URLs from, so they can never drift apart.
|
*/

Route::middleware('auth')->group(function (): void {
    // The config values may be absolute URLs ("${APP_URL}/payment/success")
    // because the gateway drivers hand them to providers; route registration
    // only wants the path component, so normalize once here.
    $callbackPath = static function (string $key, string $default): string {
        $value = (string) config('payment.callback.'.$key, $default);
        $path = parse_url($value, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : $default;
    };

    Route::get($callbackPath('success_url', '/payment/success'), [PaymentCallbackController::class, 'success'])
        ->name('payment.callback.success');

    Route::get($callbackPath('failure_url', '/payment/failure'), [PaymentCallbackController::class, 'failure'])
        ->name('payment.callback.failure');

    Route::get($callbackPath('cancel_url', '/payment/cancel'), [PaymentCallbackController::class, 'cancel'])
        ->name('payment.callback.cancel');

    Route::get($callbackPath('pending_url', '/payment/pending'), [PaymentCallbackController::class, 'pending'])
        ->name('payment.callback.pending');
});

// User-facing locale switch route (session & cookie persistence)
Route::get('/locale/{locale}', function (string $locale) {
    if (in_array($locale, ['en', 'th'], true)) {
        session(['locale' => $locale]);
        cookie()->queue(cookie('locale', $locale, 60 * 24 * 365));
    }

    return redirect()->back();
})->name('locale.switch');

Route::post('/contact', [ContactController::class, 'submit'])
    ->middleware('throttle:contact-submit')
    ->name('contact.submit');

/*
|--------------------------------------------------------------------------
| LOTTOFIN ADMIN & Operations Console Routes
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/', [LottoFinExecutiveDashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard', [LottoFinExecutiveDashboardController::class, 'index'])->name('dashboard.index');
    Route::get('/api/analytics', [LottoFinExecutiveDashboardController::class, 'analyticsApi'])->name('api.analytics');
    Route::get('/api/reconciliation', [LottoFinExecutiveDashboardController::class, 'reconciliationFeedApi'])->name('api.reconciliation');

    // Operational sub-panels
    Route::get('/draws', [LottoFinExecutiveDashboardController::class, 'index'])->name('draws.index');
    Route::get('/risk', [LottoFinExecutiveDashboardController::class, 'index'])->name('risk.index');
    Route::get('/bets', [LottoFinExecutiveDashboardController::class, 'index'])->name('bets.index');
    Route::get('/wallets', [LottoFinExecutiveDashboardController::class, 'index'])->name('wallets.index');
    Route::get('/ledger', [LottoFinExecutiveDashboardController::class, 'index'])->name('ledger.index');
    Route::get('/reconciliation', [LottoFinExecutiveDashboardController::class, 'index'])->name('reconciliation.index');
    Route::get('/audits', [LottoFinExecutiveDashboardController::class, 'index'])->name('audits.index');

    // Payments & Disbursements
    Route::get('/payments', [LottoFinExecutiveDashboardController::class, 'index'])->name('payments.index');
    Route::get('/withdrawals', [LottoFinExecutiveDashboardController::class, 'index'])->name('withdrawals.index');
    Route::post('/withdrawals/{id}/disburse', [LottoFinExecutiveDashboardController::class, 'index'])->name('withdrawals.disburse');
    Route::post('/withdrawals/{id}/reject', [LottoFinExecutiveDashboardController::class, 'index'])->name('withdrawals.reject');

    // KYC & Compliance
    Route::get('/kyc', [LottoFinExecutiveDashboardController::class, 'index'])->name('kyc.index');
    Route::get('/kyc/{id}/download', [LottoFinExecutiveDashboardController::class, 'index'])->name('kyc.download');
    Route::post('/kyc/{id}/approve', [LottoFinExecutiveDashboardController::class, 'index'])->name('kyc.approve');
    Route::post('/kyc/{id}/reject', [LottoFinExecutiveDashboardController::class, 'index'])->name('kyc.reject');
    Route::get('/compliance', [LottoFinExecutiveDashboardController::class, 'index'])->name('compliance.index');
});

/*
|--------------------------------------------------------------------------
| Agent Portal Routes
|--------------------------------------------------------------------------
*/
Route::prefix('agent')->name('agent.')->group(function (): void {
    Route::get('/', function () { return view('agent.dashboard'); })->name('dashboard');
    Route::get('/dashboard', function () { return view('agent.dashboard'); })->name('dashboard.index');
    Route::get('/commissions', function () { return view('agent.commissions'); })->name('commissions');
    Route::get('/settlements', function () { return view('agent.settlements'); })->name('settlements');
});

/*
|--------------------------------------------------------------------------
| Legacy .php URL compatibility layer (301)
|--------------------------------------------------------------------------
|
| Single home for every public .php URL the replaced site published:
| static pages, member auth surfaces, account explainer pages, the broken
| double-path member URLs, and the per-year archive pages — including the
| "lottoery" typo form search engines indexed. See LegacyRedirectController
| for the map and the rules.
|
| THIS MUST STAY THE LAST ROUTE IN THIS FILE. It only ever sees paths no
| real route claimed, because Laravel matches in registration order, and
| it answers 404 for .php paths it does not know rather than aliasing them.
|
*/

Route::match(['get', 'post'], '/{legacyPath}', [LegacyRedirectController::class, 'resolve'])
    ->where('legacyPath', '.*\.php$')
    ->name('legacy.redirect');
```

### `lang/en/bingo_lottery.php`
# TYPE: PHP translation
# PURPOSE: English Mega localization, navigation, result states, provenance, and NOT_CONFIGURED purchase state.

```php
<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Mega Lottery result surface — English (PROMPT 8)
|--------------------------------------------------------------------------
|
| NO NUMBERS LIVE IN THIS FILE. Not a 6 Mega, not a 3 Mega, not a sample draw.
| Every value a page renders comes from the database, and every string here is
| a LABEL for such a value. A translation file carrying an example result
| would, the moment someone copied it into a view, become fabricated data with
| a translator's name on it.
|
| NO CLAIM OF OFFICIAL STATUS. The phrase "official GLO" does not appear, and
| neither does any market nickname a third party uses for this draw. This
| lane presents results with their provenance attached; whether a given
| version is officially sourced is a per-row fact rendered from the
| OFFICIAL_SOURCE_VERIFIED badge, never a blanket sentence in a heading.
|
| The source_state and status blocks mirror the CLOSED vocabularies in
| config/bingo_lottery.php. A state with no entry here would render its raw
| key, which is why both lists are kept complete - and why lang/th carries the
| identical key set.
|
*/

return [

    // ---------------------------------------------------------------- SEO --
    'meta_title' => 'Mega Lottery Results',
    'meta_title_search' => 'Mega Lottery Results — Search',
    'meta_description' => 'Published Mega Lottery results with the 6 Mega, 3 Mega and 2 Mega shown together with their source, import time and record version.',
    'meta_description_year' => 'Mega Lottery results for :year, with the source and version recorded for every published draw.',

    // ------------------------------------------------------------- Page ----
    'heading' => 'Mega Lottery Results',
    'public_name' => 'Mega Lottery',
    'intro' => 'Every result below is shown together with where it came from, when it was imported and which version of the record you are reading.',
    'not_official_notice' => 'This page presents results recorded by this platform with their source attached. It is not an official government publication, and a result is only as authoritative as the source badge shown beside it.',
    'skip_to_content' => 'Skip to content',
    'latest_link' => 'Latest result',
    'history_link' => 'Historical results',
    'buy_title' => 'Buy / Ticket selection',
    'purchase_status_heading' => 'Purchase availability',
    'purchase_not_configured' => 'NOT_CONFIGURED',
    'purchase_not_configured_explainer' => 'Mega ticket selection and purchase are unavailable because no verified product-specific purchase contract is configured. No price, ticket, balance, reservation or purchase action is shown.',
    'back_to_results' => 'Back to Mega Lottery results',

    'current_result_heading' => 'Latest published result',
    'recent_draws_heading' => 'Recent draws',
    'history_heading' => 'Results history',
    'detail_heading' => 'Draw detail',
    'provenance_heading' => 'Where this result came from',
    'integrity_heading' => 'Record integrity',

    // ------------------------------------------------------------ Fields ---
    'field_first_6_mega' => '6 Mega',
    'field_three_mega' => '3 Mega',
    'field_two_mega' => '2 Mega',
    'field_date' => 'Draw date',

    'field_first_6_mega_hint' => 'Six digits, leading zeros included.',
    'field_three_mega_hint' => 'Three digits, leading zeros included.',
    'field_two_mega_hint' => 'Two digits, leading zeros included.',

    // ------------------------------------------------------------- Table ---
    'table_caption' => 'Published Mega Lottery results, newest draw first. A draw with no published numbers is shown with its fields marked as not published.',
    'col_draw_date' => 'Draw date',
    'col_first_6_mega' => '6 Mega',
    'col_three_mega' => '3 Mega',
    'col_two_mega' => '2 Mega',
    'col_source' => 'Source',
    'col_detail' => 'Detail',
    'view_detail' => 'View draw',

    // ------------------------------------------------- Missing result -------
    'no_value' => 'Not published',
    'result_unavailable_badge' => 'No result published',
    'result_unavailable_explainer' => 'This draw is on record and no numbers were published for it. Nothing is shown in place of the missing values, because a substituted zero would look like a real result.',

    // -------------------------------------------------------------- Date ---
    'date_buddhist_label' => 'Thai Buddhist year',
    'date_gregorian_label' => 'Gregorian date',
    'date_timezone_label' => 'Draw timezone',
    'published_at_label' => 'Published',

    // -------------------------------------------------------- Year nav -----
    'year_nav_heading' => 'Browse by year',
    'year_nav_empty' => 'No year has a published result yet.',
    'year_draw_count' => ':count draws',

    // -------------------------------------------------------- Search --------
    'search_heading' => 'Search results',
    'search_intro' => 'Choose what you are searching for, then enter the exact value. Leading zeros matter and are preserved exactly as typed.',
    'search_type_label' => 'Search by',
    'search_term_label' => 'Value',
    'search_term_placeholder' => 'Exact value',
    'search_submit' => 'Search',
    'search_reset' => 'Clear',
    'search_results_heading' => 'Search results',
    'search_matched_in' => 'Matched in',
    'search_hint' => 'Matching is exact: 09 finds 09 and nothing else. Nothing is matched partially.',
    'search_type_missing' => 'Choose what you are searching for before searching.',

    'search_type' => [
        '6mega' => '6 Mega',
        '3mega' => '3 Mega',
        '2mega' => '2 Mega',
        'date' => 'Draw date',
    ],

    // --------------------------------------------------------- Pagination --
    'pagination_previous' => 'Previous',
    'pagination_next' => 'Next',
    'pagination_status' => 'Page :page of :last',
    'pagination_total' => ':total draws',

    // -------------------------------------------------------- Provenance ---
    'provenance' => [
        'provider' => 'Provider',
        'source_state' => 'Source state',
        'source_identifier' => 'Source reference',
        'source_host' => 'Source host',
        'payload_fingerprint' => 'Payload fingerprint',
        'normalized_fingerprint' => 'Value fingerprint',
        'parser_version' => 'Parser version',
        'retrieved_at' => 'Retrieved',
        'imported_at' => 'Imported',
        'result_version' => 'Result version',
        'supersedes' => 'Replaces version',
        'none' => 'Not recorded',
        'explainer' => 'The fingerprints are one-way hashes of the delivered payload and of its canonical values. They let a record be matched against its source without republishing the source.',
    ],

    // --------------------------------------------------------- Integrity ---
    // --------------------------------------------------------- Integrity ---
    //
    // THIS LANE HAS NO INDEPENDENT VERIFIER, so the vocabulary below is
    // deliberately SHORTER than the Weekly lane's. There is no
    // 'signed_verified' and no 'verifier_unavailable' label here, because a
    // label the lane can never legitimately render is a claim waiting to be
    // made by accident - a future refactor that guessed at a status string
    // would find a friendly translation sitting ready for it.
    //
    'integrity' => [
        'status' => 'Integrity check',
        'canonical_version' => 'Canonical format',
        'independently_verified' => 'Independently re-derived',
        'yes' => 'Yes',
        'no' => 'No',
        'explainer' => 'The canonical form of this result is hashed when it is imported, and the hash is stored beside it. This lane has no independent verifier, so the hash shows the stored values still match what was imported; it is not evidence about who authorised the numbers.',
    ],

    'integrity_status' => [
        'integrity_hash_only' => 'Hash recorded (not signed)',
        'signature_unsupported' => 'Signature not supported in this lane',
        'fingerprint_mismatch' => 'Fingerprint did not match',
        'not_verified' => 'Not checked',
        'rejected' => 'Rejected',
    ],

    'integrity_hint' => [
        'integrity_hash_only' => 'The stored values match the hash recorded when they were imported. That hash was computed by this application, so it shows the record is internally consistent and nothing more.',
        'signature_unsupported' => 'Signature material was supplied, but this lane has no verifier able to check it, so the record was refused rather than accepted unchecked.',
        'fingerprint_mismatch' => 'The recorded hash does not match the stored values.',
        'not_verified' => 'No integrity check is recorded for this version.',
        'rejected' => 'This record was refused by the integrity check.',
    ],

    // ------------------------------------------------------ Source states --
    'source_state' => [
        'official_source_verified' => 'Official source, verified',
        'official_source_configured' => 'Official source, not yet verified',
        'internal_reconciled' => 'Internally reconciled',
        'fixture_only' => 'Test fixture data',
        'not_configured' => 'No source configured',
        'unavailable' => 'Source unavailable',
    ],

    'source_state_hint' => [
        'official_source_verified' => 'Delivered by the configured official provider and validated on import.',
        'official_source_configured' => 'An official provider is configured but has not verified this record.',
        'internal_reconciled' => 'Recorded and reconciled inside this platform rather than delivered by an official provider.',
        'fixture_only' => 'Sample data used for development and testing. It is not a real draw result.',
        'not_configured' => 'No provider is configured for this lane, so no source can be claimed.',
        'unavailable' => 'The source of this record could not be established.',
    ],

    // ------------------------------------------------------- Lookup states --
    'status' => [
        'result_found' => 'Result found.',
        'result_unavailable' => 'This draw is on record and no numbers were published for it.',
        'result_not_found' => 'No published result matches that request.',
        'no_public_data' => 'There is no published result to show yet.',
        'invalid_query' => 'That request could not be read. Check the value and try again.',
        'unavailable' => 'Results are temporarily unavailable.',
    ],

    'empty_current' => 'No Mega Lottery result has been published yet. Nothing is shown here until a result has been imported with a recorded source.',
    'empty_year' => 'No published result exists for this year.',
    'empty_search' => 'No published draw matches that search.',

    // ------------------------------------------------------- Corrections ---
    'correction_notice' => 'This draw has been corrected. You are reading version :version; the earlier version is retained in the record.',
    'conflict_notice' => 'A newer payload for this draw disagrees with the published result. Publication is on hold until the disagreement is resolved, and the previously verified result stays on this page.',
];
```

### `lang/th/bingo_lottery.php`
# TYPE: PHP translation
# PURPOSE: Thai Mega localization with matching key structure.

```php
<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Mega Lottery result surface — Thai (PROMPT 8)
|--------------------------------------------------------------------------
|
| A KEY-FOR-KEY MIRROR of lang/en/bingo_lottery.php, asserted by a test. A
| missing key would fall back to the key name and print a machine string on a
| public page, so both files carry exactly the same structure.
|
| NO NUMBERS HERE EITHER. No example draw, no sample result. Field labels such
| as 6 Mega / 3 Mega / 2 Mega describe the SHAPE of a value, never a value.
|
| Years shown to a Thai reader are Buddhist Era, but the conversion happens in
| the shared calendar service - never in this file and never in a template.
|
| NO CLAIM OF OFFICIAL STATUS. Nothing here states or implies that these
| results are an official government publication.
|
*/

return [

    // ---------------------------------------------------------------- SEO --
    'meta_title' => 'ผล Mega Lottery',
    'meta_title_search' => 'ผล Mega Lottery — ค้นหา',
    'meta_description' => 'ผล Mega Lottery ที่เผยแพร่แล้ว แสดง 6 Mega, 3 Mega และ 2 Mega พร้อมแหล่งที่มา เวลานำเข้า และเวอร์ชันของบันทึก',
    'meta_description_year' => 'ผล Mega Lottery ประจำปี :year พร้อมแหล่งที่มาและเวอร์ชันที่บันทึกไว้ของทุกงวดที่เผยแพร่',

    // ------------------------------------------------------------- Page ----
    'heading' => 'ผล Mega Lottery',
    'public_name' => 'Mega Lottery',
    'intro' => 'ผลทุกงวดด้านล่างแสดงพร้อมแหล่งที่มา เวลาที่นำเข้า และเวอร์ชันของบันทึกที่คุณกำลังอ่าน',
    'not_official_notice' => 'หน้านี้แสดงผลรางวัล Mega Lottery ที่ระบบนี้บันทึกไว้พร้อมแหล่งที่มา ไม่ใช่ประกาศอย่างเป็นทางการของหน่วยงานรัฐ และความน่าเชื่อถือของผลแต่ละรายการเป็นไปตามป้ายกำกับแหล่งที่มาที่แสดงข้างรายการนั้น',
    'skip_to_content' => 'ข้ามไปยังเนื้อหา',
    'latest_link' => 'ผลล่าสุด',
    'history_link' => 'ประวัติผลรางวัล',
    'buy_title' => 'ซื้อ / เลือกสลาก',
    'purchase_status_heading' => 'สถานะการซื้อ',
    'purchase_not_configured' => 'NOT_CONFIGURED',
    'purchase_not_configured_explainer' => 'ยังไม่เปิดการเลือกและซื้อสลาก Mega เนื่องจากยังไม่มีสัญญาการซื้อเฉพาะผลิตภัณฑ์ที่ตรวจสอบแล้ว ระบบจึงไม่แสดงราคา สลาก ยอดเงิน การกันวงเงิน หรือคำสั่งซื้อใด ๆ',
    'back_to_results' => 'กลับไปยังผล Mega Lottery',

    'current_result_heading' => 'ผลล่าสุดที่เผยแพร่',
    'recent_draws_heading' => 'งวดล่าสุด',
    'history_heading' => 'ประวัติผลรางวัล',
    'detail_heading' => 'รายละเอียดงวด',
    'provenance_heading' => 'ที่มาของผลรางวัลนี้',
    'integrity_heading' => 'ความถูกต้องของบันทึก',

    // ------------------------------------------------------------ Fields ---
    'field_first_6_mega' => '6 ตัว',
    'field_three_mega' => '3 ตัว',
    'field_two_mega' => '2 ตัว',
    'field_date' => 'วันที่ออกรางวัล',

    'field_first_6_mega_hint' => 'ตัวเลข 6 หลัก รวมเลขศูนย์นำหน้า',
    'field_three_mega_hint' => 'ตัวเลข 3 หลัก รวมเลขศูนย์นำหน้า',
    'field_two_mega_hint' => 'ตัวเลข 2 หลัก รวมเลขศูนย์นำหน้า',

    // ------------------------------------------------------------- Table ---
    'table_caption' => 'ผล Mega Lottery ที่เผยแพร่แล้ว เรียงจากงวดล่าสุด งวดที่ไม่มีการเผยแพร่ตัวเลขจะแสดงว่ายังไม่เผยแพร่ในทุกช่อง',
    'col_draw_date' => 'วันที่ออกรางวัล',
    'col_first_6_mega' => '6 ตัว',
    'col_three_mega' => '3 ตัว',
    'col_two_mega' => '2 ตัว',
    'col_source' => 'แหล่งที่มา',
    'col_detail' => 'รายละเอียด',
    'view_detail' => 'ดูงวดนี้',

    // ------------------------------------------------- Missing result -------
    'no_value' => 'ยังไม่เผยแพร่',
    'result_unavailable_badge' => 'ไม่มีผลรางวัลที่เผยแพร่',
    'result_unavailable_explainer' => 'งวดนี้มีอยู่ในบันทึก แต่ไม่มีการเผยแพร่ตัวเลข ระบบจะไม่แสดงค่าใดแทนที่ค่าที่หายไป เพราะเลขศูนย์ที่ใส่แทนจะดูเหมือนผลรางวัลจริง',

    // -------------------------------------------------------------- Date ---
    'date_buddhist_label' => 'ปีพุทธศักราช',
    'date_gregorian_label' => 'วันที่คริสต์ศักราช',
    'date_timezone_label' => 'เขตเวลาของงวด',
    'published_at_label' => 'เผยแพร่เมื่อ',

    // -------------------------------------------------------- Year nav -----
    'year_nav_heading' => 'ดูตามปี',
    'year_nav_empty' => 'ยังไม่มีปีใดที่มีผลรางวัลเผยแพร่',
    'year_draw_count' => ':count งวด',

    // -------------------------------------------------------- Search --------
    'search_heading' => 'ค้นหาผลรางวัล',
    'search_intro' => 'เลือกสิ่งที่ต้องการค้นหา แล้วกรอกค่าที่ตรงกันทุกตัวอักษร เลขศูนย์นำหน้ามีความหมายและจะถูกเก็บไว้ตรงตามที่พิมพ์',
    'search_type_label' => 'ค้นหาจาก',
    'search_term_label' => 'ค่าที่ค้นหา',
    'search_term_placeholder' => 'ค่าที่ตรงกันทุกตัวอักษร',
    'search_submit' => 'ค้นหา',
    'search_reset' => 'ล้าง',
    'search_results_heading' => 'ผลการค้นหา',
    'search_matched_in' => 'ตรงกับ',
    'search_hint' => 'การค้นหาเป็นแบบตรงทุกตัวอักษร: 09 จะพบเฉพาะ 09 เท่านั้น ไม่มีการจับคู่บางส่วน',
    'search_type_missing' => 'กรุณาเลือกสิ่งที่ต้องการค้นหาก่อนกดค้นหา',

    'search_type' => [
        '6mega' => '6 ตัว',
        '3mega' => '3 ตัว',
        '2mega' => '2 ตัว',
        'date' => 'วันที่ออกรางวัล',
    ],

    // --------------------------------------------------------- Pagination --
    'pagination_previous' => 'ก่อนหน้า',
    'pagination_next' => 'ถัดไป',
    'pagination_status' => 'หน้า :page จาก :last',
    'pagination_total' => ':total งวด',

    // -------------------------------------------------------- Provenance ---
    'provenance' => [
        'provider' => 'ผู้ให้ข้อมูล',
        'source_state' => 'สถานะแหล่งที่มา',
        'source_identifier' => 'รหัสอ้างอิงแหล่งที่มา',
        'source_host' => 'โฮสต์ของแหล่งที่มา',
        'payload_fingerprint' => 'ลายนิ้วมือข้อมูลดิบ',
        'normalized_fingerprint' => 'ลายนิ้วมือค่าที่จัดรูปแล้ว',
        'parser_version' => 'เวอร์ชันตัวอ่านข้อมูล',
        'retrieved_at' => 'ดึงข้อมูลเมื่อ',
        'imported_at' => 'นำเข้าเมื่อ',
        'result_version' => 'เวอร์ชันของผล',
        'supersedes' => 'แทนที่เวอร์ชัน',
        'none' => 'ไม่ได้บันทึกไว้',
        'explainer' => 'ลายนิ้วมือเป็นค่าแฮชทางเดียวของข้อมูลที่ได้รับและของค่าที่จัดรูปแล้ว ใช้ตรวจสอบบันทึกเทียบกับแหล่งที่มาได้โดยไม่ต้องเผยแพร่ข้อมูลต้นทางซ้ำ',
    ],

    // --------------------------------------------------------- Integrity ---
    // --------------------------------------------------------- Integrity ---
    'integrity' => [
        'status' => 'การตรวจสอบความถูกต้อง',
        'canonical_version' => 'รูปแบบมาตรฐาน',
        'independently_verified' => 'ตรวจซ้ำโดยอิสระ',
        'yes' => 'ใช่',
        'no' => 'ไม่',
        'explainer' => 'รูปแบบมาตรฐานของผลนี้ถูกแฮชเมื่อนำเข้า และเก็บค่าแฮชไว้คู่กัน ช่องทางนี้ไม่มีตัวตรวจสอบอิสระ ค่าแฮชจึงแสดงเพียงว่าค่าที่จัดเก็บยังตรงกับที่นำเข้า ไม่ใช่หลักฐานว่าใครเป็นผู้รับรองตัวเลข',
    ],

    'integrity_status' => [
        'integrity_hash_only' => 'บันทึกค่าแฮชแล้ว (ไม่มีลายเซ็น)',
        'signature_unsupported' => 'ช่องทางนี้ไม่รองรับลายเซ็น',
        'fingerprint_mismatch' => 'ลายนิ้วมือไม่ตรงกัน',
        'not_verified' => 'ยังไม่ได้ตรวจสอบ',
        'rejected' => 'ถูกปฏิเสธ',
    ],

    'integrity_hint' => [
        'integrity_hash_only' => 'ค่าที่จัดเก็บตรงกับค่าแฮชที่บันทึกไว้ตอนนำเข้า ค่าแฮชนี้คำนวณโดยแอปพลิเคชันเอง จึงแสดงเพียงว่าบันทึกสอดคล้องกันภายในเท่านั้น',
        'signature_unsupported' => 'มีการส่งข้อมูลลายเซ็นมา แต่ช่องทางนี้ไม่มีตัวตรวจสอบที่ตรวจได้ จึงปฏิเสธบันทึกแทนที่จะรับไว้โดยไม่ตรวจ',
        'fingerprint_mismatch' => 'ค่าแฮชที่บันทึกไว้ไม่ตรงกับค่าที่จัดเก็บ',
        'not_verified' => 'ไม่มีการบันทึกการตรวจสอบความถูกต้องสำหรับเวอร์ชันนี้',
        'rejected' => 'บันทึกนี้ถูกปฏิเสธจากการตรวจสอบความถูกต้อง',
    ],

    // ------------------------------------------------------ Source states --
    'source_state' => [
        'official_source_verified' => 'แหล่งข้อมูลทางการ ตรวจสอบแล้ว',
        'official_source_configured' => 'แหล่งข้อมูลทางการ ยังไม่ได้ตรวจสอบ',
        'internal_reconciled' => 'กระทบยอดภายในระบบ',
        'fixture_only' => 'ข้อมูลตัวอย่างสำหรับทดสอบ',
        'not_configured' => 'ยังไม่ได้ตั้งค่าแหล่งข้อมูล',
        'unavailable' => 'ไม่ทราบแหล่งที่มา',
    ],

    'source_state_hint' => [
        'official_source_verified' => 'ได้รับจากผู้ให้ข้อมูลทางการที่ตั้งค่าไว้ และผ่านการตรวจสอบเมื่อนำเข้า',
        'official_source_configured' => 'มีการตั้งค่าผู้ให้ข้อมูลทางการแล้ว แต่ยังไม่ได้ยืนยันบันทึกนี้',
        'internal_reconciled' => 'บันทึกและกระทบยอดภายในระบบนี้ ไม่ได้รับจากผู้ให้ข้อมูลทางการ',
        'fixture_only' => 'ข้อมูลตัวอย่างสำหรับการพัฒนาและการทดสอบ ไม่ใช่ผลรางวัลจริง',
        'not_configured' => 'ยังไม่มีผู้ให้ข้อมูลสำหรับช่องทางนี้ จึงไม่สามารถอ้างแหล่งที่มาได้',
        'unavailable' => 'ไม่สามารถระบุแหล่งที่มาของบันทึกนี้ได้',
    ],

    // ------------------------------------------------------- Lookup states --
    'status' => [
        'result_found' => 'พบผลรางวัล',
        'result_unavailable' => 'งวดนี้มีอยู่ในบันทึก แต่ไม่มีการเผยแพร่ตัวเลข',
        'result_not_found' => 'ไม่พบผลรางวัลที่เผยแพร่ตรงกับคำขอนี้',
        'no_public_data' => 'ยังไม่มีผลรางวัลที่เผยแพร่ให้แสดง',
        'invalid_query' => 'ไม่สามารถอ่านคำขอนี้ได้ กรุณาตรวจสอบค่าแล้วลองใหม่',
        'unavailable' => 'ขณะนี้ยังไม่สามารถแสดงผลรางวัลได้',
    ],

    'empty_current' => 'ยังไม่มีการเผยแพร่ผล Mega Lottery จะไม่มีการแสดงข้อมูลใดจนกว่าจะมีการนำเข้าผลพร้อมแหล่งที่มาที่บันทึกไว้',
    'empty_year' => 'ไม่มีผลรางวัลที่เผยแพร่สำหรับปีนี้',
    'empty_search' => 'ไม่พบงวดที่เผยแพร่ตรงกับการค้นหานี้',

    // ------------------------------------------------------- Corrections ---
    'correction_notice' => 'งวดนี้มีการแก้ไข คุณกำลังอ่านเวอร์ชัน :version และเวอร์ชันก่อนหน้ายังคงถูกเก็บไว้ในบันทึก',
    'conflict_notice' => 'ข้อมูลชุดใหม่ของงวดนี้ขัดแย้งกับผลที่เผยแพร่อยู่ การเผยแพร่ถูกระงับไว้จนกว่าจะแก้ไขข้อขัดแย้ง และผลที่ตรวจสอบแล้วก่อนหน้านี้ยังคงแสดงอยู่บนหน้านี้',
];
```

### `lang/en/lottery_hub.php`
# TYPE: PHP translation
# PURPOSE: Approved public Mega Lottery product label in English.

```php
<?php

declare(strict_types=1);

return [
    'meta_title' => 'Lottery Hub',
    'meta_description' => 'Browse the public lottery result lanes available on ThaiLotto. Result availability is read from each lane and is never replaced with sample values.',
    'eyebrow' => 'Public lottery hub',
    'heading_prefix' => 'Choose your',
    'heading_accent' => 'lottery lane',
    'intro' => 'Explore the result and archive surfaces that are currently configured. Each product keeps its own data, provenance and rules; this hub does not invent prices, schedules or winning numbers.',
    'products_heading' => 'Lottery products',
    'all_products' => 'All products',
    'compare_heading' => 'Compare public lanes',
    'compare_product' => 'Product',
    'compare_data' => 'Data state',
    'compare_result' => 'Result page',
    'product_label' => 'Lottery lane',
    'view_results' => 'View results',
    'buy_or_select' => 'Buy or select',
    'current_result_available' => 'A published result is available from this lane.',
    'current_result_unavailable' => 'No published result is available from this lane right now.',
    'unavailable_heading' => 'Lottery catalogue unavailable',
    'unavailable_body' => 'No enabled public lottery lane is configured. Nothing is displayed as a substitute.',
    'trust_heading' => 'Data and provenance',
    'trust_body' => 'Result cards are backed by the lane-specific public projection. Source state is shown on result pages, and missing data is presented as unavailable rather than estimated.',
    'breadcrumb' => 'Lottery navigation',
    'buy_title' => 'Buy / Ticket Selection',
    'buy_eyebrow' => 'Product purchase surface',
    'buy_meta_description' => ':product purchase and ticket selection surface.',
    'purchase_unavailable_heading' => 'Purchase is not configured for this product lane',
    'purchase_unavailable_body' => 'The repository has a canonical purchase pipeline for operator bets, but it does not currently expose a verified product-specific mapping for this result lane. This page therefore accepts no number, price, discount, wallet instruction or purchase request. No fake success can be shown.',
    'purchase_safety_heading' => 'Purchase safeguards',
    'purchase_safety' => [
        'price' => [
            'heading' => 'Server-authoritative price',
            'body' => 'A purchase surface must obtain price and totals from the server. No browser value is accepted here.',
        ],
        'wallet' => [
            'heading' => 'Wallet and ledger',
            'body' => 'A future lane adapter must reuse the existing lock, reservation, debit and ledger pipeline.',
        ],
        'success' => [
            'heading' => 'No false success',
            'body' => 'Until that adapter exists, this page remains unavailable and makes no purchase claim.',
        ],
    ],
    'back_to_results' => 'Back to results',
    'state' => [
        'RESULT_AVAILABLE' => 'Result available',
        'RESULT_FOUND' => 'Result available',
        'NO_PUBLIC_DATA' => 'No public data',
        'RESULT_NOT_FOUND' => 'No public result',
        'RESULT_UNAVAILABLE' => 'Result unavailable',
        'INVALID_QUERY' => 'Unavailable',
        'UNAVAILABLE' => 'Unavailable',
    ],
    'products' => [
        'national_lottery' => [
            'title' => 'National Lottery',
            'description' => 'Published National Lottery draws, searchable history, year archives and source-backed result details.',
        ],
        'weekly_lottery' => [
            'title' => 'Weekly Lottery',
            'description' => 'Published Weekly Lottery draws with preserved fixed-width values, archives, search and provenance.',
        ],
        'bingo_lottery' => [
            'title' => 'Mega Lottery',
            'description' => 'A separate result lane shown only when its own public route and result data are configured.',
        ],
        'pcso_lottery' => [
            'title' => 'PCSO Lottery',
            'description' => 'A separate result lane shown only when its own public route and result data are configured.',
        ],
    ],
];
```

### `lang/th/lottery_hub.php`
# TYPE: PHP translation
# PURPOSE: Approved public Mega Lottery product label in Thai locale.

```php
<?php

declare(strict_types=1);

return [
    'meta_title' => 'Lottery Hub',
    'meta_description' => 'เรียกดูช่องผลสลากสาธารณะของ ThaiLotto โดยสถานะผลลัพธ์อ่านจากแต่ละช่องทางและไม่แทนที่ด้วยข้อมูลตัวอย่าง',
    'eyebrow' => 'ศูนย์กลางสลากสาธารณะ',
    'heading_prefix' => 'เลือก',
    'heading_accent' => 'ช่องสลากของคุณ',
    'intro' => 'สำรวจหน้าผลลัพธ์และคลังข้อมูลที่เปิดใช้งานอยู่ แต่ละผลิตภัณฑ์มีข้อมูล แหล่งที่มา และกติกาของตนเอง ศูนย์กลางนี้จะไม่สร้างราคา ตารางเวลา หรือเลขรางวัลขึ้นเอง',
    'products_heading' => 'ผลิตภัณฑ์สลาก',
    'all_products' => 'ผลิตภัณฑ์ทั้งหมด',
    'compare_heading' => 'เปรียบเทียบช่องทางสาธารณะ',
    'compare_product' => 'ผลิตภัณฑ์',
    'compare_data' => 'สถานะข้อมูล',
    'compare_result' => 'หน้าผลลัพธ์',
    'product_label' => 'ช่องสลาก',
    'view_results' => 'ดูผลลัพธ์',
    'buy_or_select' => 'ซื้อหรือเลือกเลข',
    'current_result_available' => 'ช่องทางนี้มีผลลัพธ์ที่เผยแพร่แล้ว',
    'current_result_unavailable' => 'ขณะนี้ช่องทางนี้ยังไม่มีผลลัพธ์ที่เผยแพร่',
    'unavailable_heading' => 'ไม่สามารถโหลดรายการสลาก',
    'unavailable_body' => 'ยังไม่มีช่องสลากสาธารณะที่เปิดใช้งานและกำหนดค่าไว้ จึงไม่มีการแสดงข้อมูลทดแทน',
    'trust_heading' => 'ข้อมูลและแหล่งที่มา',
    'trust_body' => 'การ์ดผลลัพธ์ใช้ข้อมูลจาก public projection ของช่องทางนั้นโดยตรง สถานะแหล่งที่มาแสดงในหน้าผลลัพธ์ และข้อมูลที่ขาดจะแสดงเป็นไม่พร้อมใช้งานแทนการคาดเดา',
    'breadcrumb' => 'การนำทางสลาก',
    'buy_title' => 'ซื้อ / เลือกตั๋ว',
    'buy_eyebrow' => 'หน้าการซื้อผลิตภัณฑ์',
    'buy_meta_description' => 'หน้าการซื้อและเลือกตั๋วสำหรับ :product',
    'purchase_unavailable_heading' => 'ยังไม่ได้กำหนดการซื้อสำหรับช่องผลิตภัณฑ์นี้',
    'purchase_unavailable_body' => 'ระบบมีเส้นทางการซื้อแบบมาตรฐานสำหรับเดิมพันของผู้ให้บริการ แต่ยังไม่มีการเชื่อมโยงเฉพาะผลิตภัณฑ์ที่ผ่านการยืนยันสำหรับช่องผลลัพธ์นี้ หน้านี้จึงไม่รับเลข ราคา ส่วนลด คำสั่งกระเป๋าเงิน หรือคำขอซื้อ และจะไม่แสดงความสำเร็จปลอม',
    'purchase_safety_heading' => 'มาตรการความปลอดภัยในการซื้อ',
    'purchase_safety' => [
        'price' => [
            'heading' => 'ราคาอ้างอิงจากเซิร์ฟเวอร์',
            'body' => 'หน้าซื้อต้องรับราคาและยอดรวมจากเซิร์ฟเวอร์เท่านั้น หน้านี้ไม่ยอมรับค่าจากเบราว์เซอร์',
        ],
        'wallet' => [
            'heading' => 'กระเป๋าเงินและบัญชีแยกประเภท',
            'body' => 'อะแดปเตอร์ในอนาคตต้องใช้ระบบล็อก สำรองยอด หักยอด และบัญชีแยกประเภทที่มีอยู่',
        ],
        'success' => [
            'heading' => 'ไม่มีความสำเร็จปลอม',
            'body' => 'จนกว่าจะมีอะแดปเตอร์ ช่องนี้จะแสดงไม่พร้อมใช้งานและไม่อ้างว่ามีการซื้อ',
        ],
    ],
    'back_to_results' => 'กลับไปที่ผลลัพธ์',
    'state' => [
        'RESULT_AVAILABLE' => 'มีผลลัพธ์',
        'RESULT_FOUND' => 'มีผลลัพธ์',
        'NO_PUBLIC_DATA' => 'ไม่มีข้อมูลสาธารณะ',
        'RESULT_NOT_FOUND' => 'ไม่มีผลลัพธ์สาธารณะ',
        'RESULT_UNAVAILABLE' => 'ผลลัพธ์ไม่พร้อมใช้งาน',
        'INVALID_QUERY' => 'ไม่พร้อมใช้งาน',
        'UNAVAILABLE' => 'ไม่พร้อมใช้งาน',
    ],
    'products' => [
        'national_lottery' => [
            'title' => 'National Lottery',
            'description' => 'ผลการออกรางวัล National Lottery ที่เผยแพร่แล้ว พร้อมการค้นหา ประวัติ คลังรายปี และรายละเอียดที่มีแหล่งที่มา',
        ],
        'weekly_lottery' => [
            'title' => 'Weekly Lottery',
            'description' => 'ผลการออกรางวัล Weekly Lottery ที่เผยแพร่แล้ว พร้อมค่าความกว้างคงที่ ประวัติ การค้นหา และแหล่งที่มา',
        ],
        'bingo_lottery' => [
            'title' => 'Mega Lottery',
            'description' => 'ช่องผลลัพธ์แยกต่างหากที่แสดงเมื่อกำหนดเส้นทางสาธารณะและข้อมูลผลลัพธ์ของช่องนั้นแล้วเท่านั้น',
        ],
        'pcso_lottery' => [
            'title' => 'PCSO Lottery',
            'description' => 'ช่องผลลัพธ์แยกต่างหากที่แสดงเมื่อกำหนดเส้นทางสาธารณะและข้อมูลผลลัพธ์ของช่องนั้นแล้วเท่านั้น',
        ],
    ],
];
```

### `resources/css/bingo-lottery.css`
# TYPE: CSS
# PURPOSE: Data-free retained Mega visual hooks with unsupported product claims removed.

```css
/*
 * Mega Lottery - Dark Gold Result Surface Stylesheet
 * Data-free visual hooks retained for compatibility with the public result lane.
 */

:root {
    --bl-gold-primary: #D4AF37;
    --bl-gold-light: #FFF6D6;
    --bl-gold-dark: #8C6D1F;
    --bl-gold-glow: rgba(212, 175, 55, 0.25);
    --bl-bg-dark: #0B0904;
    --bl-card-bg: #141007;
    --bl-card-border: rgba(212, 175, 55, 0.25);
}

.bl-ball {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 3.5rem;
    height: 3.5rem;
    border-radius: 9999px;
    background: radial-gradient(circle at 35% 30%, #FFF8E7 0%, #E5C365 30%, #B8860B 70%, #5E4300 100%);
    color: #0B0904;
    font-weight: 900;
    font-size: 1.6rem;
    font-family: 'Outfit', sans-serif;
    box-shadow: 0 6px 15px rgba(0, 0, 0, 0.6), inset 0 2px 4px rgba(255, 255, 255, 0.6), inset 0 -3px 6px rgba(0, 0, 0, 0.5);
    border: 1px solid rgba(255, 246, 214, 0.4);
    transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.bl-ball:hover {
    transform: scale(1.08) translateY(-2px);
}

.bl-pedestal {
    position: relative;
    background: linear-gradient(180deg, #1F190B 0%, #120F07 100%);
    border: 1px solid rgba(212, 175, 55, 0.3);
    border-radius: 1.25rem;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5), inset 0 1px 0 rgba(212, 175, 55, 0.3);
}

.bl-pedestal::after {
    content: '';
    position: absolute;
    bottom: -6px;
    left: 10%;
    right: 10%;
    height: 6px;
    background: radial-gradient(ellipse at center, rgba(212, 175, 55, 0.4) 0%, transparent 80%);
}

.bl-keypad-btn {
    background: linear-gradient(180deg, #1C170E 0%, #100D06 100%);
    border: 1px solid rgba(212, 175, 55, 0.2);
    color: #F5E6B8;
    font-family: 'Outfit', sans-serif;
    font-weight: 700;
    transition: all 0.15s ease-in-out;
}

.bl-keypad-btn:hover {
    border-color: #D4AF37;
    background: linear-gradient(180deg, #2A2213 0%, #17130A 100%);
    color: #FFFFFF;
    box-shadow: 0 0 12px rgba(212, 175, 55, 0.25);
    transform: translateY(-1px);
}

.bl-tab-active {
    background: linear-gradient(180deg, #2A2312 0%, #17130A 100%) !important;
    border-color: #D4AF37 !important;
    color: #F5E6B8 !important;
    box-shadow: 0 0 15px rgba(212, 175, 55, 0.25);
}
```

### `resources/css/lottery.css`
# TYPE: CSS
# PURPOSE: Shared responsive dark/gold/glass/3D result styling for Weekly and Mega.

```css
/* Lottery-specific styles (number grid, bet slip, countdown). */
.lottery-number-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(3.5rem, 1fr));
    gap: 0.5rem;
}

/* Public result lanes: one readable dark-gold glass system for Weekly and Mega. */
.wl-page {
    --wl-gold: #d4af37;
    --wl-gold-soft: #f5e6b8;
    --wl-ink: #0b0904;
    --wl-panel: rgba(20, 16, 7, 0.84);
    --wl-line: rgba(212, 175, 55, 0.24);
    color: #f8fafc;
    min-height: 30rem;
}

.wl-main {
    position: relative;
    isolation: isolate;
    overflow: hidden;
    padding: clamp(1rem, 2vw, 2rem);
    border: 1px solid rgba(212, 175, 55, 0.12);
    border-radius: 1.75rem;
    background:
        radial-gradient(circle at 10% 0%, rgba(212, 175, 55, 0.12), transparent 32%),
        radial-gradient(circle at 90% 15%, rgba(16, 185, 129, 0.07), transparent 28%),
        linear-gradient(145deg, rgba(15, 23, 42, 0.96), rgba(11, 9, 4, 0.98));
    box-shadow: 0 1.5rem 4rem rgba(0, 0, 0, 0.26), inset 0 1px 0 rgba(255, 255, 255, 0.05);
}

.wl-main::before {
    position: absolute;
    z-index: -1;
    inset: 0;
    pointer-events: none;
    content: '';
    background-image: linear-gradient(rgba(212, 175, 55, 0.025) 1px, transparent 1px), linear-gradient(90deg, rgba(212, 175, 55, 0.025) 1px, transparent 1px);
    background-size: 2rem 2rem;
    mask-image: linear-gradient(to bottom, black, transparent 72%);
}

.wl-skip-link {
    position: absolute;
    z-index: 10;
    left: 1rem;
    top: -5rem;
    padding: 0.65rem 0.9rem;
    border-radius: 0.75rem;
    background: var(--wl-gold);
    color: var(--wl-ink);
    font-weight: 800;
}

.wl-skip-link:focus {
    top: 1rem;
}

.wl-breadcrumb {
    margin-bottom: 1.5rem;
    font-size: 0.8rem;
}

.wl-breadcrumb__link,
.wl-link,
.wl-card__link,
.wl-table__link,
.wl-pagination__link {
    color: var(--wl-gold-soft);
    font-weight: 750;
    text-decoration: none;
}

.wl-breadcrumb__link:hover,
.wl-link:hover,
.wl-card__link:hover,
.wl-table__link:hover,
.wl-pagination__link:hover {
    color: #fff;
    text-decoration: underline;
    text-underline-offset: 0.18em;
}

.wl-header {
    max-width: 62rem;
    margin: 0 auto 2.5rem;
    text-align: center;
}

.wl-eyebrow {
    margin: 0 0 0.65rem;
    color: var(--wl-gold);
    font-size: 0.72rem;
    font-weight: 850;
    letter-spacing: 0.18em;
    text-transform: uppercase;
}

.wl-title {
    margin: 0;
    color: var(--wl-gold-soft);
    font-size: clamp(2rem, 4vw, 4rem);
    font-weight: 950;
    letter-spacing: -0.04em;
    line-height: 1.05;
    text-shadow: 0 0 2rem rgba(212, 175, 55, 0.15);
}

.wl-intro,
.wl-disclaimer {
    max-width: 54rem;
    margin: 1rem auto 0;
    color: #cbd5e1;
    font-size: 0.96rem;
    line-height: 1.75;
}

.wl-disclaimer {
    color: #94a3b8;
    font-size: 0.82rem;
}

.wl-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 0.65rem;
    margin-top: 1.25rem;
}

.wl-actions .wl-link,
.wl-link {
    display: inline-flex;
    align-items: center;
    min-height: 2.55rem;
    padding: 0.65rem 0.95rem;
    border: 1px solid var(--wl-line);
    border-radius: 0.85rem;
    background: rgba(212, 175, 55, 0.08);
}

.mega-hero {
    display: grid;
    grid-template-columns: minmax(11rem, 0.7fr) minmax(0, 1.3fr);
    align-items: center;
    gap: clamp(1.25rem, 4vw, 3.5rem);
    max-width: 75rem;
    margin: 0 auto 2rem;
    padding: clamp(1.25rem, 4vw, 3rem);
    border: 1px solid rgba(212, 175, 55, 0.24);
    border-radius: 1.5rem;
    background: radial-gradient(circle at 25% 35%, rgba(212, 175, 55, 0.16), transparent 28%), linear-gradient(135deg, rgba(28, 23, 14, 0.94), rgba(8, 12, 24, 0.9));
    box-shadow: 0 1.5rem 4rem rgba(0, 0, 0, 0.25), inset 0 1px 0 rgba(255, 255, 255, 0.06);
}

.mega-hero__orb {
    position: relative;
    display: grid;
    place-items: center;
    width: min(13rem, 52vw);
    aspect-ratio: 1;
    margin: auto;
    border: 1px solid rgba(245, 230, 184, 0.45);
    border-radius: 50%;
    background: radial-gradient(circle at 34% 26%, #fff8e7 0, #e5c365 9%, #9f761c 34%, #17120a 65%, #080b16 100%);
    box-shadow: 0 0 1rem rgba(212, 175, 55, 0.45), 0 1.5rem 3rem rgba(0, 0, 0, 0.5), inset -1rem -1.25rem 2rem rgba(0, 0, 0, 0.54), inset 0.5rem 0.6rem 1.2rem rgba(255, 255, 255, 0.36);
}

.mega-hero__orb::before,
.mega-hero__orb::after {
    position: absolute;
    border: 1px solid rgba(212, 175, 55, 0.35);
    border-radius: 50%;
    content: '';
    transform: rotate(-24deg);
}

.mega-hero__orb::before {
    inset: -0.8rem 1.2rem;
}

.mega-hero__orb::after {
    inset: 1.2rem -0.8rem;
    transform: rotate(58deg);
}

.mega-hero__ring {
    position: absolute;
    border: 1px solid rgba(255, 248, 231, 0.42);
    border-radius: 50%;
}

.mega-hero__ring--one {
    inset: 12%;
}

.mega-hero__ring--two {
    inset: 21%;
    border-color: rgba(212, 175, 55, 0.28);
}

.mega-hero__orb-label {
    position: relative;
    color: #1a1306;
    font-size: clamp(1.2rem, 3vw, 1.8rem);
    font-weight: 950;
    letter-spacing: 0.14em;
    text-shadow: 0 1px 0 rgba(255, 255, 255, 0.45);
}

.mega-hero__copy h2 {
    margin: 0;
    color: var(--wl-gold-soft);
    font-size: clamp(1.8rem, 4vw, 3.25rem);
    font-weight: 950;
    letter-spacing: -0.04em;
}

.mega-hero__copy p:last-child {
    max-width: 42rem;
    margin: 0.8rem 0 0;
    color: #cbd5e1;
    line-height: 1.75;
}

.wl-section {
    max-width: 75rem;
    margin: 0 auto 2rem;
    padding: clamp(1rem, 2vw, 1.75rem);
    border: 1px solid var(--wl-line);
    border-radius: 1.35rem;
    background: linear-gradient(145deg, rgba(28, 23, 14, 0.9), rgba(13, 11, 5, 0.88));
    box-shadow: 0 1.25rem 3rem rgba(0, 0, 0, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.035);
}

.wl-section--empty {
    text-align: center;
}

.wl-section__heading {
    margin: 0 0 1rem;
    color: var(--wl-gold-soft);
    font-size: clamp(1.15rem, 2vw, 1.6rem);
    font-weight: 850;
}

.wl-empty {
    margin: 0;
    color: #fbbf24;
    font-weight: 750;
}

.wl-card {
    position: relative;
    overflow: hidden;
    padding: clamp(1.1rem, 2.5vw, 2rem);
    border: 1px solid var(--wl-line);
    border-radius: 1.25rem;
    background: linear-gradient(145deg, rgba(28, 23, 14, 0.98), rgba(13, 11, 5, 0.98));
    box-shadow: 0 1rem 2.5rem rgba(0, 0, 0, 0.25), inset 0 1px 0 rgba(255, 255, 255, 0.04);
}

.wl-card::after {
    position: absolute;
    right: -4rem;
    bottom: -5rem;
    width: 12rem;
    height: 12rem;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(212, 175, 55, 0.11), transparent 68%);
    content: '';
    pointer-events: none;
}

.wl-card__heading {
    margin: 0 0 1rem;
    color: var(--wl-gold-soft);
    font-size: 1.2rem;
    font-weight: 850;
}

.wl-card__header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid rgba(212, 175, 55, 0.15);
}

.wl-card__date,
.wl-card__date-main,
.wl-card__date-iso,
.wl-card__date-be {
    display: block;
}

.wl-card__date {
    margin: 0;
}

.wl-card__date-main {
    color: #e2e8f0;
    font-size: 1.05rem;
    font-weight: 800;
}

.wl-card__date-iso,
.wl-card__date-be {
    margin-top: 0.3rem;
    color: #94a3b8;
    font-size: 0.78rem;
}

.wl-card__numbers {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.8rem;
    margin: 1.4rem 0 0;
}

.wl-card__number {
    padding: 1rem;
    border: 1px solid rgba(212, 175, 55, 0.18);
    border-radius: 1rem;
    background: rgba(18, 15, 8, 0.86);
    text-align: center;
}

.wl-card__number-label {
    color: var(--wl-gold);
    font-size: 0.72rem;
    font-weight: 850;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.wl-card__number-value {
    margin: 0.55rem 0 0;
}

.wl-digits {
    color: var(--wl-gold-soft);
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: clamp(1.7rem, 4vw, 2.65rem);
    font-weight: 950;
    letter-spacing: 0.12em;
}

.wl-card__number-missing,
.wl-table__missing {
    color: #fbbf24;
    font-size: 0.8rem;
    font-weight: 750;
}

.wl-card__actions {
    position: relative;
    z-index: 1;
    margin: 1.25rem 0 0;
}

.wl-card__unavailable {
    display: grid;
    gap: 0.45rem;
    margin: 1.2rem 0 0;
    padding: 0.9rem 1rem;
    border: 1px solid rgba(251, 191, 36, 0.28);
    border-radius: 0.9rem;
    background: rgba(251, 191, 36, 0.06);
}

.wl-card__unavailable-badge {
    color: #fde68a;
    font-weight: 850;
}

.wl-card__unavailable-text {
    color: #cbd5e1;
    font-size: 0.86rem;
    line-height: 1.6;
}

.wl-detail__dates {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr));
    gap: 0.75rem;
    margin: 1.25rem 0 0;
}

.wl-detail__dates > div {
    padding: 0.85rem;
    border: 1px solid rgba(212, 175, 55, 0.14);
    border-radius: 0.85rem;
    background: rgba(15, 23, 42, 0.28);
}

.wl-detail__dates dt,
.wl-source__term {
    color: #94a3b8;
    font-size: 0.72rem;
    font-weight: 750;
    text-transform: uppercase;
}

.wl-detail__dates dd {
    margin: 0.3rem 0 0;
    color: #f8fafc;
    font-weight: 750;
}

.wl-table-wrap {
    overflow-x: auto;
    border: 1px solid var(--wl-line);
    border-radius: 1rem;
}

.wl-table {
    width: 100%;
    min-width: 48rem;
    border-collapse: collapse;
    background: rgba(20, 16, 7, 0.7);
}

.wl-table__caption {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    clip-path: inset(50%);
    white-space: nowrap;
}

.wl-table th,
.wl-table td {
    padding: 0.9rem 0.8rem;
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    text-align: left;
    vertical-align: top;
}

.wl-table thead th {
    color: var(--wl-gold-soft);
    background: rgba(28, 23, 14, 0.95);
    font-size: 0.73rem;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}

.wl-table tbody th {
    color: #f8fafc;
}

.wl-table tbody td {
    color: #cbd5e1;
}

.wl-table__date-alt {
    display: block;
    margin-top: 0.2rem;
    color: #64748b;
    font-size: 0.72rem;
}

.wl-source {
    display: grid;
    gap: 0.55rem;
}

.wl-source__badge {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    width: fit-content;
    padding: 0.35rem 0.55rem;
    border: 1px solid rgba(148, 163, 184, 0.28);
    border-radius: 999px;
    color: #cbd5e1;
    font-size: 0.72rem;
    font-weight: 750;
}

.wl-source--official .wl-source__badge {
    border-color: rgba(52, 211, 153, 0.35);
    color: #a7f3d0;
}

.wl-source--fixture .wl-source__badge,
.wl-source--unknown .wl-source__badge {
    border-color: rgba(251, 191, 36, 0.35);
    color: #fde68a;
}

.wl-source__dot {
    width: 0.5rem;
    height: 0.5rem;
    border-radius: 50%;
    background: currentColor;
    box-shadow: 0 0 0.65rem currentColor;
}

.wl-source__hint,
.wl-source__explainer {
    margin: 0;
    color: #94a3b8;
    font-size: 0.82rem;
    line-height: 1.65;
}

.wl-source__facts {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr));
    gap: 0.65rem;
    margin: 0;
}

.wl-source__fact {
    padding: 0.75rem;
    border: 1px solid rgba(212, 175, 55, 0.12);
    border-radius: 0.75rem;
    background: rgba(15, 23, 42, 0.25);
}

.wl-source__value {
    margin: 0.3rem 0 0;
    overflow-wrap: anywhere;
    color: #e2e8f0;
    font-size: 0.78rem;
}

.wl-source__hash {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
}

.wl-years {
    max-width: 75rem;
    margin: 0 auto;
    padding: 1rem 0 0;
}

.wl-years__heading {
    margin: 0 0 0.75rem;
    color: var(--wl-gold-soft);
    font-size: 1rem;
    font-weight: 850;
}

.wl-years__list {
    display: flex;
    flex-wrap: wrap;
    gap: 0.6rem;
    margin: 0;
    padding: 0;
    list-style: none;
}

.wl-years__link {
    display: grid;
    gap: 0.1rem;
    min-width: 7.5rem;
    padding: 0.65rem 0.8rem;
    border: 1px solid rgba(212, 175, 55, 0.2);
    border-radius: 0.8rem;
    background: rgba(212, 175, 55, 0.05);
    color: #e2e8f0;
    text-decoration: none;
}

.wl-years__link:hover,
.wl-years__link--active {
    border-color: var(--wl-gold);
    background: rgba(212, 175, 55, 0.12);
}

.wl-years__label {
    color: var(--wl-gold-soft);
    font-weight: 850;
}

.wl-years__count,
.wl-years__alt {
    color: #94a3b8;
    font-size: 0.72rem;
}

.wl-years__empty {
    color: #94a3b8;
}

.wl-pagination {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: center;
    gap: 1rem;
    margin-top: 1rem;
    color: #cbd5e1;
    font-size: 0.86rem;
}

.wl-pagination__status {
    color: #94a3b8;
}

@media (max-width: 640px) {
    .mega-hero {
        grid-template-columns: 1fr;
        text-align: center;
    }

    .mega-hero__copy p:last-child {
        margin-right: auto;
        margin-left: auto;
    }

    .wl-main {
        padding: 0.75rem;
        border-radius: 1rem;
    }

    .wl-card__header {
        display: grid;
    }

    .wl-card__numbers {
        grid-template-columns: 1fr;
    }

    .wl-table {
        min-width: 42rem;
    }

    .wl-years__link {
        min-width: 6.8rem;
    }
}

@media (prefers-reduced-motion: reduce) {
    .wl-page *,
    .wl-page *::before,
    .wl-page *::after {
        scroll-behavior: auto !important;
        transition-duration: 0.01ms !important;
    }
}
```

### `resources/js/bingo-lottery.js`
# TYPE: JavaScript
# PURPOSE: Safe search-only progressive enhancement; no lottery or purchase source of truth.

```javascript
/**
 * Public Mega Lottery enhancement layer.
 *
 * Result numbers, draw dates, source state and all purchase capability remain
 * server-authoritative. This file deliberately has no countdown, random pick,
 * odds, payout, price, ticket, wallet, balance or settlement behavior.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const search = document.querySelector('[data-wl-search]');

        if (!search) {
            return;
        }

        const term = search.querySelector('input[name="term"]');

        if (term) {
            term.addEventListener('input', function () {
                term.value = term.value.replace(/[^0-9-]/g, '');
            });
        }
    });
})();
```

### `resources/views/bingo-lottery/index.blade.php`
# TYPE: Blade view
# PURPOSE: Source-driven Mega landing page with 3D decorative hero, result, search, history, provenance, and navigation.

```blade
@extends('layouts.app')

@section('title', $meta['title'])
@section('meta_description', $meta['description'])
@section('meta_canonical', $meta['canonical'])
@section('meta_robots', $meta['robots'])
@section('meta_og_title', $meta['og_title'])
@section('meta_og_description', $meta['og_description'])
@section('meta_og_type', $meta['og_type'])
@section('meta_og_url', $meta['og_url'])

@push('styles')
    @vite(['resources/css/bingo-lottery.css'])
@endpush

@section('content')
@php
    $projection = is_array($current ?? null) ? $current : [];
    $searchPayload = is_array($search ?? null) ? $search : null;
    $searchRows = is_array($searchPayload['matches'] ?? null) ? $searchPayload['matches'] : [];
@endphp
<div class="wl-page" data-wl-page="bingo-lottery" data-wl-locale="{{ $meta['lang'] }}">
    <a class="wl-skip-link" href="#wl-main">{{ trans('bingo_lottery.skip_to_content') }}</a>
    <main id="wl-main" class="wl-main" tabindex="-1">
        <header class="wl-header">
            <p class="wl-eyebrow">{{ trans('bingo_lottery.public_name') }}</p>
            <h1 class="wl-title">{{ trans('bingo_lottery.heading') }}</h1>
            <p class="wl-intro">{{ trans('bingo_lottery.intro') }}</p>
            <p class="wl-disclaimer">{{ trans('bingo_lottery.not_official_notice') }}</p>
            <nav class="wl-actions" aria-label="{{ trans('bingo_lottery.public_name') }}">
                <a class="wl-link" href="{{ route('bingo-lottery.latest') }}">{{ trans('bingo_lottery.latest_link') }}</a>
                <a class="wl-link" href="{{ route('bingo-lottery.history') }}">{{ trans('bingo_lottery.history_link') }}</a>
                <a class="wl-link" href="{{ route('bingo-lottery.buy') }}">{{ trans('bingo_lottery.buy_title') }}</a>
            </nav>
        </header>

        <section class="mega-hero" aria-label="{{ trans('bingo_lottery.public_name') }}">
            <div class="mega-hero__orb" aria-hidden="true">
                <span class="mega-hero__ring mega-hero__ring--one"></span>
                <span class="mega-hero__ring mega-hero__ring--two"></span>
                <span class="mega-hero__orb-label">MEGA</span>
            </div>
            <div class="mega-hero__copy">
                <p class="wl-eyebrow">{{ trans('bingo_lottery.current_result_heading') }}</p>
                <h2>{{ trans('bingo_lottery.public_name') }}</h2>
                <p>{{ trans('bingo_lottery.intro') }}</p>
            </div>
        </section>

        <section class="wl-section" aria-labelledby="mega-current-heading">
            <h2 id="mega-current-heading" class="wl-section__heading">{{ trans('bingo_lottery.current_result_heading') }}</h2>
            <x-bingo-lottery.result-card :result="$projection" :is-thai="$is_thai" />
        </section>

        @if ($recent !== [])
            <section class="wl-section" aria-labelledby="mega-recent-heading">
                <h2 id="mega-recent-heading" class="wl-section__heading">{{ trans('bingo_lottery.recent_draws_heading') }}</h2>
                <div class="grid gap-4 md:grid-cols-2">
                    @foreach ($recent as $row)
                        <x-bingo-lottery.result-card :result="$row" :is-thai="$is_thai" />
                    @endforeach
                </div>
            </section>
        @endif

        <x-bingo-lottery.search-form
            :action="$routes['search']"
            :types="$search_types"
            :max-length="$max_search_length"
            :type="$searchPayload['query']['type'] ?? null"
            :term="$searchPayload['query']['term'] ?? ''"
        />

        @if ($searchPayload !== null)
            <section class="wl-section" aria-labelledby="mega-search-heading">
                <h2 id="mega-search-heading" class="wl-section__heading">{{ trans('bingo_lottery.search_results_heading') }}</h2>
                <p class="wl-empty" role="status">{{ trans('bingo_lottery.status.'.strtolower((string) ($searchPayload['status'] ?? 'unavailable'))) }}</p>
                <x-bingo-lottery.result-table :rows="$searchRows" :is-thai="$is_thai" />
            </section>
        @endif

        <x-bingo-lottery.year-nav :years="$years" :active-year="$active_year" :is-thai="$is_thai" />
    </main>
</div>
@endsection

@push('scripts')
    @vite(['resources/js/bingo-lottery.js'])
@endpush
```

### `resources/views/bingo-lottery/buy.blade.php`
# TYPE: Blade view
# PURPOSE: Fail-closed Mega ticket-selection/purchase page.

```blade
@extends('layouts.app')

@section('title', $product_title)
@section('meta_description', $product_description)
@section('meta_canonical', $canonical)
@section('meta_robots', 'noindex,follow')
@section('meta_og_title', $product_title)
@section('meta_og_description', $product_description)
@section('meta_og_type', 'website')
@section('meta_og_url', $canonical)

@push('styles')
    @vite(['resources/css/bingo-lottery.css'])
@endpush

@section('content')
<div class="wl-page" data-wl-page="bingo-lottery-buy">
    <a class="wl-skip-link" href="#wl-main">{{ trans('bingo_lottery.skip_to_content') }}</a>
    <main id="wl-main" class="wl-main" tabindex="-1">
        <header class="wl-header">
            <p class="wl-eyebrow">{{ trans('bingo_lottery.public_name') }}</p>
            <h1 class="wl-title">{{ trans('bingo_lottery.buy_title') }}</h1>
            <p class="wl-disclaimer">{{ trans('bingo_lottery.not_official_notice') }}</p>
        </header>
        <section class="wl-section wl-section--empty" role="status" aria-labelledby="mega-purchase-status">
            <h2 id="mega-purchase-status" class="wl-section__heading">{{ trans('bingo_lottery.purchase_status_heading') }}</h2>
            <p class="wl-empty">{{ trans('bingo_lottery.purchase_not_configured') }}</p>
            <p class="wl-intro">{{ trans('bingo_lottery.purchase_not_configured_explainer') }}</p>
            <a class="wl-link" href="{{ $back_url }}">{{ trans('bingo_lottery.back_to_results') }}</a>
        </section>
    </main>
</div>
@endsection
```

### `resources/views/bingo-lottery/draw-detail.blade.php`
# TYPE: Blade view
# PURPOSE: Mega single-draw detail surface.

```blade
@extends('layouts.app')

@section('title', $meta['title'])
@section('meta_description', $meta['description'])
@section('meta_canonical', $meta['canonical'])
@section('meta_robots', $meta['robots'])
@section('meta_og_title', $meta['og_title'])
@section('meta_og_description', $meta['og_description'])
@section('meta_og_type', $meta['og_type'])
@section('meta_og_url', $meta['og_url'])

@push('styles')
    @vite(['resources/css/bingo-lottery.css'])
@endpush

@section('content')
@php
    $projection = is_array($current ?? null) ? $current : [];
    $available = (bool) ($projection['available'] ?? false);
    $status = strtolower((string) ($projection['status'] ?? 'unavailable'));
    $draw = is_array($projection['draw'] ?? null) ? $projection['draw'] : [];
    $date = is_array($draw['date'] ?? null) ? $draw['date'] : [];
@endphp
<div class="wl-page wl-page--detail" data-wl-page="bingo-lottery-draw-detail" data-wl-locale="{{ $meta['lang'] }}">
    <a class="wl-skip-link" href="#wl-main">{{ trans('bingo_lottery.skip_to_content') }}</a>
    <main id="wl-main" class="wl-main" tabindex="-1">
        <nav class="wl-breadcrumb" aria-label="{{ trans('bingo_lottery.heading') }}">
            <a class="wl-breadcrumb__link" href="{{ $routes['index'] }}">{{ trans('bingo_lottery.heading') }}</a>
        </nav>
        <header class="wl-header">
            <p class="wl-eyebrow">{{ trans('bingo_lottery.detail_heading') }}</p>
            <h1 class="wl-title">{{ trans('bingo_lottery.detail_heading') }}</h1>
            <p class="wl-disclaimer">{{ trans('bingo_lottery.not_official_notice') }}</p>
        </header>

        @if (! $available)
            <section class="wl-section wl-section--empty" role="status">
                <p class="wl-empty">{{ trans('bingo_lottery.status.'.$status) }}</p>
                <a class="wl-link" href="{{ $routes['index'] }}">{{ trans('bingo_lottery.heading') }}</a>
            </section>
        @else
            <section class="wl-section" aria-labelledby="mega-draw-result">
                <h2 id="mega-draw-result" class="wl-section__heading">{{ trans('bingo_lottery.current_result_heading') }}</h2>
                <x-bingo-lottery.result-card :result="$projection" :is-thai="$is_thai" :show-link="false" />
                <dl class="wl-detail__dates">
                    <div><dt>{{ trans('bingo_lottery.date_gregorian_label') }}</dt><dd><time datetime="{{ $date['iso'] ?? '' }}">{{ $date['iso'] ?? '' }}</time></dd></div>
                    <div><dt>{{ trans('bingo_lottery.date_buddhist_label') }}</dt><dd>{{ $date['buddhist_year'] ?? '' }}</dd></div>
                    @if (($draw['timezone'] ?? '') !== '')
                        <div><dt>{{ trans('bingo_lottery.date_timezone_label') }}</dt><dd>{{ $draw['timezone'] }}</dd></div>
                    @endif
                    @if (($draw['published_at'] ?? null) !== null)
                        <div><dt>{{ trans('bingo_lottery.published_at_label') }}</dt><dd><time datetime="{{ $draw['published_at'] }}">{{ $draw['published_at'] }}</time></dd></div>
                    @endif
                </dl>
            </section>
            <section class="wl-section" aria-labelledby="mega-draw-source">
                <h2 id="mega-draw-source" class="wl-section__heading">{{ trans('bingo_lottery.provenance_heading') }}</h2>
                <x-bingo-lottery.source-status :provenance="(array) ($projection['provenance'] ?? [])" :integrity="$projection['integrity'] ?? null" />
            </section>
        @endif

        <x-bingo-lottery.year-nav :years="$years" :active-year="$active_year" :is-thai="$is_thai" />
    </main>
</div>
@endsection

@push('scripts')
    @vite(['resources/js/bingo-lottery.js'])
@endpush
```

### `resources/views/bingo-lottery/latest-result.blade.php`
# TYPE: Blade view
# PURPOSE: Mega latest-result surface with real Prize Verification link.

```blade
@extends('layouts.app')

@section('title', $meta['title'])
@section('meta_description', $meta['description'])
@section('meta_canonical', $meta['canonical'])
@section('meta_robots', $meta['robots'])
@section('meta_og_title', $meta['og_title'])
@section('meta_og_description', $meta['og_description'])
@section('meta_og_type', $meta['og_type'])
@section('meta_og_url', $meta['og_url'])

@push('styles')
    @vite(['resources/css/bingo-lottery.css'])
@endpush

@section('content')
@php
    $projection = is_array($current ?? null) ? $current : [];
    $recentRows = is_array($recent ?? null) ? $recent : [];
@endphp
<div class="wl-page" data-wl-page="bingo-lottery-latest" data-wl-locale="{{ $meta['lang'] }}">
    <a class="wl-skip-link" href="#wl-main">{{ trans('bingo_lottery.skip_to_content') }}</a>
    <main id="wl-main" class="wl-main" tabindex="-1">
        <header class="wl-header">
            <p class="wl-eyebrow">{{ trans('bingo_lottery.current_result_heading') }}</p>
            <h1 class="wl-title">{{ trans('bingo_lottery.heading') }}</h1>
            <p class="wl-intro">{{ trans('bingo_lottery.intro') }}</p>
            <p class="wl-disclaimer">{{ trans('bingo_lottery.not_official_notice') }}</p>
            <nav class="wl-actions" aria-label="{{ trans('bingo_lottery.current_result_heading') }}">
                <a class="wl-link" href="{{ route('prize-verification') }}">{{ trans('contact.link_prize_verification') }}</a>
            </nav>
        </header>
        <section class="wl-section" aria-labelledby="mega-latest-heading">
            <h2 id="mega-latest-heading" class="wl-section__heading">{{ trans('bingo_lottery.current_result_heading') }}</h2>
            <x-bingo-lottery.result-card :result="$projection" :is-thai="$is_thai" />
        </section>
        @if ($recentRows !== [])
            <section class="wl-section" aria-labelledby="mega-recent-heading">
                <h2 id="mega-recent-heading" class="wl-section__heading">{{ trans('bingo_lottery.recent_draws_heading') }}</h2>
                <div class="grid gap-4 md:grid-cols-2">
                    @foreach ($recentRows as $row)
                        <x-bingo-lottery.result-card :result="$row" :is-thai="$is_thai" />
                    @endforeach
                </div>
            </section>
        @endif
        <x-bingo-lottery.year-nav :years="$years" :active-year="$active_year" :is-thai="$is_thai" />
    </main>
</div>
@endsection

@push('scripts')
    @vite(['resources/js/bingo-lottery.js'])
@endpush
```

### `resources/views/bingo-lottery/history.blade.php`
# TYPE: Blade view
# PURPOSE: Mega historical-results surface with server pagination.

```blade
@extends('layouts.app')

@section('title', $meta['title'])
@section('meta_description', $meta['description'])
@section('meta_canonical', $meta['canonical'])
@section('meta_robots', $meta['robots'])
@section('meta_og_title', $meta['og_title'])
@section('meta_og_description', $meta['og_description'])
@section('meta_og_type', $meta['og_type'])
@section('meta_og_url', $meta['og_url'])

@push('styles')
    @vite(['resources/css/bingo-lottery.css'])
@endpush

@section('content')
@php
    $historyPayload = is_array($history ?? null) ? $history : [];
    $rows = is_array($historyPayload['rows'] ?? null) ? $historyPayload['rows'] : [];
    $pagination = is_array($historyPayload['pagination'] ?? null) ? $historyPayload['pagination'] : null;
    $historyStatus = strtolower((string) ($historyPayload['status'] ?? 'no_public_data'));
    $year = $active_year !== null ? (int) $active_year : null;
@endphp
<div class="wl-page" data-wl-page="bingo-lottery-history" data-wl-locale="{{ $meta['lang'] }}">
    <a class="wl-skip-link" href="#wl-main">{{ trans('bingo_lottery.skip_to_content') }}</a>
    <main id="wl-main" class="wl-main" tabindex="-1">
        <header class="wl-header">
            <p class="wl-eyebrow">{{ trans('bingo_lottery.history_heading') }}</p>
            <h1 class="wl-title">{{ trans('bingo_lottery.history_heading') }}</h1>
            <p class="wl-disclaimer">{{ trans('bingo_lottery.not_official_notice') }}</p>
        </header>
        @if ($historyPayload === [] || $historyStatus !== 'result_found')
            <section class="wl-section wl-section--empty" role="status">
                <p class="wl-empty">{{ trans('bingo_lottery.status.'.$historyStatus) }}</p>
            </section>
        @else
            <section class="wl-section" aria-labelledby="mega-history-heading">
                <h2 id="mega-history-heading" class="wl-section__heading">{{ trans('bingo_lottery.history_heading') }}@if ($year !== null) — {{ $year }}@endif</h2>
                <x-bingo-lottery.result-table :rows="$rows" :is-thai="$is_thai" :pagination="$pagination" pagination-route="bingo-lottery.history" :pagination-params="[]" />
            </section>
        @endif
        <x-bingo-lottery.year-nav :years="$years" :active-year="$active_year" :is-thai="$is_thai" />
    </main>
</div>
@endsection

@push('scripts')
    @vite(['resources/js/bingo-lottery.js'])
@endpush
```

### `resources/views/bingo-lottery/year.blade.php`
# TYPE: Blade view
# PURPOSE: Mega year archive surface.

```blade
@extends('layouts.app')

@section('title', $meta['title'])
@section('meta_description', $meta['description'])
@section('meta_canonical', $meta['canonical'])
@section('meta_robots', $meta['robots'])
@section('meta_og_title', $meta['og_title'])
@section('meta_og_description', $meta['og_description'])
@section('meta_og_type', $meta['og_type'])
@section('meta_og_url', $meta['og_url'])

@push('styles')
    @vite(['resources/css/bingo-lottery.css'])
@endpush

@section('content')
@php
    $historyPayload = is_array($history ?? null) ? $history : [];
    $rows = is_array($historyPayload['rows'] ?? null) ? $historyPayload['rows'] : [];
    $pagination = is_array($historyPayload['pagination'] ?? null) ? $historyPayload['pagination'] : null;
    $historyStatus = strtolower((string) ($historyPayload['status'] ?? 'no_public_data'));
    $year = $active_year !== null ? (int) $active_year : null;
@endphp
<div class="wl-page" data-wl-page="bingo-lottery-year" data-wl-locale="{{ $meta['lang'] }}">
    <a class="wl-skip-link" href="#wl-main">{{ trans('bingo_lottery.skip_to_content') }}</a>
    <main id="wl-main" class="wl-main" tabindex="-1">
        <header class="wl-header">
            <p class="wl-eyebrow">{{ trans('bingo_lottery.history_heading') }}</p>
            <h1 class="wl-title">{{ trans('bingo_lottery.history_heading') }}@if ($year !== null) — {{ $year }}@endif</h1>
            <p class="wl-disclaimer">{{ trans('bingo_lottery.not_official_notice') }}</p>
        </header>
        @if ($historyPayload === [] || $historyStatus !== 'result_found')
            <section class="wl-section wl-section--empty" role="status">
                <p class="wl-empty">{{ trans('bingo_lottery.status.'.$historyStatus) }}</p>
            </section>
        @else
            <section class="wl-section" aria-labelledby="mega-year-heading">
                <h2 id="mega-year-heading" class="wl-section__heading">{{ trans('bingo_lottery.history_heading') }}</h2>
                <x-bingo-lottery.result-table :rows="$rows" :is-thai="$is_thai" :pagination="$pagination" pagination-route="bingo-lottery.year" :pagination-params="['year' => $year]" />
            </section>
        @endif
        <x-bingo-lottery.year-nav :years="$years" :active-year="$active_year" :is-thai="$is_thai" />
    </main>
</div>
@endsection

@push('scripts')
    @vite(['resources/js/bingo-lottery.js'])
@endpush
```

### `resources/views/bingo-lottery/result-detail.blade.php`
# TYPE: Blade view
# PURPOSE: Mega result-detail alias surface.

```blade
@extends('layouts.app')

@section('title', $meta['title'])
@section('meta_description', $meta['description'])
@section('meta_canonical', $meta['canonical'])
@section('meta_robots', $meta['robots'])
@section('meta_og_title', $meta['og_title'])
@section('meta_og_description', $meta['og_description'])
@section('meta_og_type', $meta['og_type'])
@section('meta_og_url', $meta['og_url'])

@push('styles')
    @vite(['resources/css/bingo-lottery.css'])
@endpush

@section('content')
@php
    $projection = is_array($current ?? null) ? $current : [];
    $available = (bool) ($projection['available'] ?? false);
    $status = strtolower((string) ($projection['status'] ?? 'unavailable'));
@endphp
<div class="wl-page wl-page--detail" data-wl-page="bingo-lottery-result-detail" data-wl-locale="{{ $meta['lang'] }}">
    <a class="wl-skip-link" href="#wl-main">{{ trans('bingo_lottery.skip_to_content') }}</a>
    <main id="wl-main" class="wl-main" tabindex="-1">
        <header class="wl-header">
            <p class="wl-eyebrow">{{ trans('bingo_lottery.detail_heading') }}</p>
            <h1 class="wl-title">{{ trans('bingo_lottery.detail_heading') }}</h1>
            <p class="wl-disclaimer">{{ trans('bingo_lottery.not_official_notice') }}</p>
        </header>
        <section class="wl-section" aria-labelledby="mega-result-detail-heading">
            <h2 id="mega-result-detail-heading" class="wl-section__heading">{{ trans('bingo_lottery.current_result_heading') }}</h2>
            @if (! $available)
                <p class="wl-empty" role="status">{{ trans('bingo_lottery.status.'.$status) }}</p>
            @else
                <x-bingo-lottery.result-card :result="$projection" :is-thai="$is_thai" :show-link="false" />
                <section class="wl-section wl-section--provenance" aria-labelledby="mega-result-provenance">
                    <h3 id="mega-result-provenance" class="wl-section__heading">{{ trans('bingo_lottery.provenance_heading') }}</h3>
                    <x-bingo-lottery.source-status :provenance="(array) ($projection['provenance'] ?? [])" :integrity="$projection['integrity'] ?? null" />
                </section>
            @endif
        </section>
        <x-bingo-lottery.year-nav :years="$years" :active-year="$active_year" :is-thai="$is_thai" />
    </main>
</div>
@endsection

@push('scripts')
    @vite(['resources/js/bingo-lottery.js'])
@endpush
```

### `resources/views/components/bingo-lottery/result-card.blade.php`
# TYPE: Blade component
# PURPOSE: Mega result card using the canonical draw route.

```blade
@props([
    'result' => [],
    'isThai' => false,
    'heading' => null,
    'showLink' => true,
])

{{--
    One draw's numbers, in full (PROMPT 8, file 21).

    LEADING ZEROS REACH THE SCREEN INTACT. Every value printed below arrives as
    a STRING that the model padded to its documented width. This template does
    not call number_format, does not cast, and does not concatenate a value
    into arithmetic. '049' prints as 049 and '09' prints as 09.

    A MISSING RESULT IS RENDERED AS MISSING. When the draw published nothing,
    each field shows the translated "not published" wording and the card
    carries an explicit badge saying so. There is no branch in this file that
    can substitute a zero for an absent value - the value is null and null
    renders as words, not digits.

    NO BUSINESS LOGIC. No date maths (the projection carries both calendars
    already), no source reasoning (the badge component owns that), no prize
    calculation of any kind - this product has no prize model. This file
    arranges values.
--}}

@php
    $available = (bool) ($result['available'] ?? false);
    $hasNumbers = (bool) ($result['has_numbers'] ?? false);
    $status = (string) ($result['status'] ?? 'UNAVAILABLE');
    $draw = (array) ($result['draw'] ?? []);
    $date = (array) ($draw['date'] ?? []);
    $numbers = (array) ($result['numbers'] ?? []);
    $provenance = (array) ($result['provenance'] ?? []);
    $integrity = $result['integrity'] ?? null;

    $displayDate = $isThai
        ? (string) ($date['display_th'] ?? '')
        : (string) ($date['display_en'] ?? '');

    $reference = (string) ($draw['reference'] ?? '');

    $fields = [
        'first_6_mega' => $numbers['first_6_mega'] ?? null,
        'three_mega' => $numbers['three_mega'] ?? null,
        'two_mega' => $numbers['two_mega'] ?? null,
    ];
@endphp

<article class="wl-card" data-wl-card="result" data-wl-status="{{ $status }}">
    @if ($heading !== null)
        <h2 class="wl-card__heading">{{ $heading }}</h2>
    @endif

    @unless ($available)
        <p class="wl-card__empty" role="status">{{ trans('bingo_lottery.status.'.strtolower($status)) }}</p>
    @else
        <header class="wl-card__header">
            <p class="wl-card__date">
                <span class="wl-card__date-main">{{ $displayDate }}</span>
                {{-- Both calendars are always available. The Gregorian/ISO
                     value is machine readable and is what a reader can match
                     against an internal record. --}}
                <time class="wl-card__date-iso" datetime="{{ $date['iso'] ?? '' }}">
                    {{ trans('bingo_lottery.date_gregorian_label') }}: {{ $date['iso'] ?? '' }}
                </time>
                <span class="wl-card__date-be">
                    {{ trans('bingo_lottery.date_buddhist_label') }}: {{ $date['buddhist_year'] ?? '' }}
                </span>
            </p>

            <x-bingo-lottery.source-status :provenance="$provenance" :compact="true" />
        </header>

        @unless ($hasNumbers)
            <p class="wl-card__unavailable" role="status">
                <span class="wl-card__unavailable-badge">{{ trans('bingo_lottery.result_unavailable_badge') }}</span>
                <span class="wl-card__unavailable-text">{{ trans('bingo_lottery.result_unavailable_explainer') }}</span>
            </p>
        @endunless

        <dl class="wl-card__numbers">
            @foreach ($fields as $field => $value)
                <div class="wl-card__number wl-card__number--{{ str_replace('_', '-', $field) }}">
                    <dt class="wl-card__number-label">{{ trans('bingo_lottery.field_'.$field) }}</dt>
                    <dd class="wl-card__number-value">
                        @if ($value === null || $value === '')
                            <span class="wl-card__number-missing">{{ trans('bingo_lottery.no_value') }}</span>
                        @else
                            {{-- String in, string out. --}}
                            <span class="wl-digits" data-wl-field="{{ $field }}">{{ $value }}</span>
                        @endif
                    </dd>
                </div>
            @endforeach
        </dl>

        @if ($showLink && $reference !== '')
            <p class="wl-card__actions">
                <a class="wl-card__link" href="{{ route('bingo-lottery.show', ['draw' => $reference]) }}">
                    {{ trans('bingo_lottery.view_detail') }}
                </a>
            </p>
        @endif
    @endunless
</article>
```

### `resources/views/components/bingo-lottery/result-table.blade.php`
# TYPE: Blade component
# PURPOSE: Mega result table using the canonical draw route.

```blade
@props([
    'rows' => [],
    'isThai' => false,
    'caption' => null,
    'pagination' => null,
    'paginationRoute' => null,
    'paginationParams' => [],
])

{{--
    Accessible, responsive history table (PROMPT 8, file 20).

    ACCESSIBILITY IS STRUCTURAL, NOT DECORATIVE
    - a real <caption> names the table for a screen reader landing on it;
    - every column <th> carries scope="col";
    - the date cell of each body row is a <th scope="row">, so a row reads as
      "18 September 2026, 6 Mega 001234" instead of a bare number salad;
    - each numeric cell carries data-label, which the stylesheet uses in the
      stacked mobile layout so a cell never appears without its heading;
    - the pagination is a <nav> with an aria-label and rel=prev/next;
    - the source column carries a text status, never colour alone.

    RESPONSIVE WITHOUT LOSING DATA. Below the breakpoint each row becomes a
    block and the data-label attributes keep every value paired with its field
    name. 6 Mega, 3 Mega and 2 Mega are NEVER display:none - a column hidden on a
    phone is a missing result, not a tidier layout. Verified at 320, 375, 768,
    1024 and 1440.

    A DRAW WITH NO NUMBERS IS STILL A ROW. Its three cells read "not
    published". Hiding the row would misrepresent the record: the draw
    happened, and the honest history shows the gap.
--}}

@php
    $caption = $caption ?? trans('bingo_lottery.table_caption');
    $pagination = is_array($pagination) ? $pagination : null;
    $columns = ['first_6_mega' => 'col_first_6_mega', 'three_mega' => 'col_three_mega', 'two_mega' => 'col_two_mega'];
@endphp

<div class="wl-table-wrap">
    <table class="wl-table" data-wl-table="results">
        <caption class="wl-table__caption">{{ $caption }}</caption>

        <thead class="wl-table__head">
            <tr>
                <th scope="col" class="wl-table__th wl-table__th--date">{{ trans('bingo_lottery.col_draw_date') }}</th>
                <th scope="col" class="wl-table__th">{{ trans('bingo_lottery.col_first_6_mega') }}</th>
                <th scope="col" class="wl-table__th">{{ trans('bingo_lottery.col_three_mega') }}</th>
                <th scope="col" class="wl-table__th">{{ trans('bingo_lottery.col_two_mega') }}</th>
                <th scope="col" class="wl-table__th">{{ trans('bingo_lottery.col_source') }}</th>
                <th scope="col" class="wl-table__th">{{ trans('bingo_lottery.col_detail') }}</th>
            </tr>
        </thead>

        <tbody class="wl-table__body">
            @forelse ($rows as $row)
                @php
                    $draw = (array) ($row['draw'] ?? []);
                    $date = (array) ($draw['date'] ?? []);
                    $numbers = (array) ($row['numbers'] ?? []);
                    $provenance = (array) ($row['provenance'] ?? []);
                    $reference = (string) ($draw['reference'] ?? '');
                    $hasNumbers = (bool) ($row['has_numbers'] ?? false);
                    $displayDate = $isThai
                        ? (string) ($date['display_th'] ?? '')
                        : (string) ($date['display_en'] ?? '');
                @endphp

                <tr @class(['wl-table__row', 'wl-table__row--unavailable' => ! $hasNumbers])>
                    <th scope="row" class="wl-table__cell wl-table__cell--date" data-label="{{ trans('bingo_lottery.col_draw_date') }}">
                        <time datetime="{{ $date['iso'] ?? '' }}">{{ $displayDate }}</time>
                        <span class="wl-table__date-alt">{{ $date['iso'] ?? '' }}</span>
                    </th>

                    @foreach ($columns as $field => $labelKey)
                        <td class="wl-table__cell" data-label="{{ trans('bingo_lottery.'.$labelKey) }}">
                            @if (($numbers[$field] ?? null) === null || ($numbers[$field] ?? '') === '')
                                <span class="wl-table__missing">{{ trans('bingo_lottery.no_value') }}</span>
                            @else
                                <span class="wl-digits" data-wl-field="{{ $field }}">{{ $numbers[$field] }}</span>
                            @endif
                        </td>
                    @endforeach

                    <td class="wl-table__cell wl-table__cell--source" data-label="{{ trans('bingo_lottery.col_source') }}">
                        <x-bingo-lottery.source-status :provenance="$provenance" :compact="true" />
                    </td>

                    <td class="wl-table__cell wl-table__cell--detail" data-label="{{ trans('bingo_lottery.col_detail') }}">
                        @if ($reference !== '')
                            <a class="wl-table__link" href="{{ route('bingo-lottery.show', ['draw' => $reference]) }}">
                                {{ trans('bingo_lottery.view_detail') }}
                            </a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr class="wl-table__row wl-table__row--empty">
                    <td class="wl-table__cell wl-table__cell--empty" colspan="6">
                        {{ trans('bingo_lottery.empty_search') }}
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if ($pagination !== null && (int) ($pagination['last_page'] ?? 1) > 1 && $paginationRoute !== null)
    <nav class="wl-pagination" aria-label="{{ trans('bingo_lottery.history_heading') }}">
        @php
            $page = (int) ($pagination['page'] ?? 1);
            $last = (int) ($pagination['last_page'] ?? 1);
        @endphp

        @if ((bool) ($pagination['has_previous'] ?? false))
            <a
                class="wl-pagination__link wl-pagination__link--prev"
                rel="prev"
                href="{{ route($paginationRoute, array_merge($paginationParams, ['page' => $page - 1])) }}"
            >{{ trans('bingo_lottery.pagination_previous') }}</a>
        @endif

        <span class="wl-pagination__status">
            {{ trans('bingo_lottery.pagination_status', ['page' => $page, 'last' => $last]) }}
            <span class="wl-pagination__total">{{ trans('bingo_lottery.pagination_total', ['total' => (int) ($pagination['total'] ?? 0)]) }}</span>
        </span>

        @if ((bool) ($pagination['has_next'] ?? false))
            <a
                class="wl-pagination__link wl-pagination__link--next"
                rel="next"
                href="{{ route($paginationRoute, array_merge($paginationParams, ['page' => $page + 1])) }}"
            >{{ trans('bingo_lottery.pagination_next') }}</a>
        @endif
    </nav>
@endif
```

### `resources/views/weekly-lottery/index.blade.php`
# TYPE: Blade view
# PURPOSE: Weekly landing navigation exposing latest and historical aliases.

```blade
@extends('layouts.app')

@section('title', $meta['title'].'')
@section('meta_description', $meta['description'])
@section('meta_canonical', $meta['canonical'])
@section('meta_robots', $meta['robots'])
@section('meta_og_title', $meta['og_title'])
@section('meta_og_description', $meta['og_description'])
@section('meta_og_type', $meta['og_type'])
@section('meta_og_url', $meta['og_url'])

@push('styles')
    @vite(['resources/css/weekly-lottery.css'])
@endpush

@section('content')
@php
    $projection = is_array($current ?? null) ? $current : [];
    $historyRows = is_array($history['rows'] ?? null) ? $history['rows'] : [];
    $searchMatches = is_array($search['matches'] ?? null) ? $search['matches'] : [];
@endphp
<div class="wl-page" data-wl-page="weekly-lottery" data-wl-locale="{{ $meta['lang'] }}">
    <a class="wl-skip-link" href="#wl-main">{{ trans('weekly_lottery.skip_to_content') }}</a>
    <main id="wl-main" class="wl-main" tabindex="-1">
        <header class="wl-header"><p class="wl-eyebrow">{{ trans('weekly_lottery.current_result_heading') }}</p><h1 class="wl-title">{{ trans('weekly_lottery.heading') }}</h1><p class="wl-intro">{{ trans('weekly_lottery.intro') }}</p><p class="wl-disclaimer">{{ trans('weekly_lottery.not_official_notice') }}</p><nav class="wl-actions" aria-label="{{ trans('weekly_lottery.heading') }}"><a class="wl-link" href="{{ route('weekly-lottery.latest') }}">{{ trans('weekly_lottery.current_result_heading') }}</a><a class="wl-link" href="{{ route('weekly-lottery.history') }}">{{ trans('weekly_lottery.history_heading') }}</a><a class="wl-link" href="{{ route('weekly-lottery.buy') }}">{{ trans('lottery_hub.buy_title') }}</a></nav></header>

        @if ($projection !== [])<section class="wl-section" aria-labelledby="wl-current-heading"><h2 id="wl-current-heading" class="wl-section__heading">{{ trans('weekly_lottery.current_result_heading') }}</h2><x-weekly-lottery.result-card :result="$projection" :is-thai="$is_thai" /></section>@else<section class="wl-section wl-section--empty" role="status"><p class="wl-empty">{{ trans('weekly_lottery.empty_current') }}</p></section>@endif

        @if ($recent !== [])<section class="wl-section" aria-labelledby="wl-recent-heading"><h2 id="wl-recent-heading" class="wl-section__heading">{{ trans('weekly_lottery.recent_draws_heading') }}</h2><div class="grid gap-4 md:grid-cols-2">@foreach ($recent as $row)<x-weekly-lottery.result-card :result="$row" :is-thai="$is_thai" />@endforeach</div></section>@endif

        <x-weekly-lottery.search-form :action="$routes['search']" :types="$search_types" :max-length="$max_search_length" :type="$search['query']['type'] ?? null" :term="$search['query']['term'] ?? ''" />
        @if ($search !== null)<section class="wl-section" aria-labelledby="wl-search-results"><h2 id="wl-search-results" class="wl-section__heading">{{ trans('weekly_lottery.search_results_heading') }}</h2><p class="wl-empty">{{ trans('weekly_lottery.status.'.strtolower((string) ($search['status'] ?? 'unavailable'))) }}</p><x-weekly-lottery.result-table :rows="$searchMatches" :is-thai="$is_thai" /></section>@endif

        @if ($history !== null)<section class="wl-section" aria-labelledby="wl-history-heading"><h2 id="wl-history-heading" class="wl-section__heading">{{ trans('weekly_lottery.history_heading') }}@if ($active_year !== null) — {{ $active_year }}@endif</h2><x-weekly-lottery.result-table :rows="$historyRows" :is-thai="$is_thai" /><x-weekly-lottery.year-nav :years="$years" :active-year="$active_year" :is-thai="$is_thai" /></section>@else<x-weekly-lottery.year-nav :years="$years" :active-year="$active_year ?? null" :is-thai="$is_thai" />@endif
    </main>
</div>
@endsection

@push('scripts')
    @vite(['resources/js/weekly-lottery.js'])
@endpush
```

### `resources/views/weekly-lottery/draw-detail.blade.php`
# TYPE: Blade view
# PURPOSE: Weekly single-draw detail surface.

```blade
@extends('layouts.app')

@section('title', $meta['title'])
@section('meta_description', $meta['description'])
@section('meta_canonical', $meta['canonical'])
@section('meta_robots', $meta['robots'])
@section('meta_og_title', $meta['og_title'])
@section('meta_og_description', $meta['og_description'])
@section('meta_og_type', $meta['og_type'])
@section('meta_og_url', $meta['og_url'])

@push('styles')
    @vite(['resources/css/weekly-lottery.css'])
@endpush

@section('content')
@php
    $projection = is_array($current ?? null) ? $current : [];
    $available = (bool) ($projection['available'] ?? false);
    $status = strtolower((string) ($projection['status'] ?? 'unavailable'));
    $draw = is_array($projection['draw'] ?? null) ? $projection['draw'] : [];
    $date = is_array($draw['date'] ?? null) ? $draw['date'] : [];
@endphp
<div class="wl-page wl-page--detail" data-wl-page="weekly-lottery-draw-detail" data-wl-locale="{{ $meta['lang'] }}">
    <a class="wl-skip-link" href="#wl-main">{{ trans('weekly_lottery.skip_to_content') }}</a>
    <main id="wl-main" class="wl-main" tabindex="-1">
        <nav class="wl-breadcrumb" aria-label="{{ trans('weekly_lottery.heading') }}">
            <a class="wl-breadcrumb__link" href="{{ $routes['index'] }}">{{ trans('weekly_lottery.heading') }}</a>
        </nav>
        <header class="wl-header">
            <p class="wl-eyebrow">{{ trans('weekly_lottery.detail_heading') }}</p>
            <h1 class="wl-title">{{ trans('weekly_lottery.detail_heading') }}</h1>
            <p class="wl-disclaimer">{{ trans('weekly_lottery.not_official_notice') }}</p>
        </header>

        @if (! $available)
            <section class="wl-section wl-section--empty" role="status">
                <p class="wl-empty">{{ trans('weekly_lottery.status.'.$status) }}</p>
                <a class="wl-link" href="{{ $routes['index'] }}">{{ trans('weekly_lottery.heading') }}</a>
            </section>
        @else
            <section class="wl-section" aria-labelledby="weekly-draw-result">
                <h2 id="weekly-draw-result" class="wl-section__heading">{{ trans('weekly_lottery.current_result_heading') }}</h2>
                <x-weekly-lottery.result-card :result="$projection" :is-thai="$is_thai" :show-link="false" />
                <dl class="wl-detail__dates">
                    <div><dt>{{ trans('weekly_lottery.date_gregorian_label') }}</dt><dd><time datetime="{{ $date['iso'] ?? '' }}">{{ $date['iso'] ?? '' }}</time></dd></div>
                    <div><dt>{{ trans('weekly_lottery.date_buddhist_label') }}</dt><dd>{{ $date['buddhist_year'] ?? '' }}</dd></div>
                    @if (($draw['timezone'] ?? '') !== '')
                        <div><dt>{{ trans('weekly_lottery.date_timezone_label') }}</dt><dd>{{ $draw['timezone'] }}</dd></div>
                    @endif
                    @if (($draw['published_at'] ?? null) !== null)
                        <div><dt>{{ trans('weekly_lottery.published_at_label') }}</dt><dd><time datetime="{{ $draw['published_at'] }}">{{ $draw['published_at'] }}</time></dd></div>
                    @endif
                </dl>
            </section>
            <section class="wl-section" aria-labelledby="weekly-draw-source">
                <h2 id="weekly-draw-source" class="wl-section__heading">{{ trans('weekly_lottery.provenance_heading') }}</h2>
                <x-weekly-lottery.source-status :provenance="(array) ($projection['provenance'] ?? [])" :integrity="$projection['integrity'] ?? null" />
            </section>
        @endif

        <x-weekly-lottery.year-nav :years="$years" :active-year="$active_year" :is-thai="$is_thai" />
    </main>
</div>
@endsection

@push('scripts')
    @vite(['resources/js/weekly-lottery.js'])
@endpush
```

### `resources/views/weekly-lottery/latest-result.blade.php`
# TYPE: Blade view
# PURPOSE: Weekly latest-result surface with real Prize Verification link.

```blade
@extends('layouts.app')

@section('title', $meta['title'])
@section('meta_description', $meta['description'])
@section('meta_canonical', $meta['canonical'])
@section('meta_robots', $meta['robots'])
@section('meta_og_title', $meta['og_title'])
@section('meta_og_description', $meta['og_description'])
@section('meta_og_type', $meta['og_type'])
@section('meta_og_url', $meta['og_url'])

@push('styles')
    @vite(['resources/css/weekly-lottery.css'])
@endpush

@section('content')
@php
    $projection = is_array($current ?? null) ? $current : [];
    $recentRows = is_array($recent ?? null) ? $recent : [];
@endphp
<div class="wl-page" data-wl-page="weekly-lottery-latest" data-wl-locale="{{ $meta['lang'] }}">
    <a class="wl-skip-link" href="#wl-main">{{ trans('weekly_lottery.skip_to_content') }}</a>
    <main id="wl-main" class="wl-main" tabindex="-1">
        <header class="wl-header">
            <p class="wl-eyebrow">{{ trans('weekly_lottery.current_result_heading') }}</p>
            <h1 class="wl-title">{{ trans('weekly_lottery.heading') }}</h1>
            <p class="wl-intro">{{ trans('weekly_lottery.intro') }}</p>
            <p class="wl-disclaimer">{{ trans('weekly_lottery.not_official_notice') }}</p>
            <nav class="wl-actions" aria-label="{{ trans('weekly_lottery.current_result_heading') }}">
                <a class="wl-link" href="{{ route('prize-verification') }}">{{ trans('contact.link_prize_verification') }}</a>
            </nav>
        </header>

        <section class="wl-section" aria-labelledby="weekly-latest-heading">
            <h2 id="weekly-latest-heading" class="wl-section__heading">{{ trans('weekly_lottery.current_result_heading') }}</h2>
            <x-weekly-lottery.result-card :result="$projection" :is-thai="$is_thai" />
        </section>

        @if ($recentRows !== [])
            <section class="wl-section" aria-labelledby="weekly-recent-heading">
                <h2 id="weekly-recent-heading" class="wl-section__heading">{{ trans('weekly_lottery.recent_draws_heading') }}</h2>
                <div class="grid gap-4 md:grid-cols-2">
                    @foreach ($recentRows as $row)
                        <x-weekly-lottery.result-card :result="$row" :is-thai="$is_thai" />
                    @endforeach
                </div>
            </section>
        @endif

        <x-weekly-lottery.year-nav :years="$years" :active-year="$active_year" :is-thai="$is_thai" />
    </main>
</div>
@endsection

@push('scripts')
    @vite(['resources/js/weekly-lottery.js'])
@endpush
```

### `resources/views/weekly-lottery/history.blade.php`
# TYPE: Blade view
# PURPOSE: Weekly historical-results surface with server pagination.

```blade
@extends('layouts.app')

@section('title', $meta['title'])
@section('meta_description', $meta['description'])
@section('meta_canonical', $meta['canonical'])
@section('meta_robots', $meta['robots'])
@section('meta_og_title', $meta['og_title'])
@section('meta_og_description', $meta['og_description'])
@section('meta_og_type', $meta['og_type'])
@section('meta_og_url', $meta['og_url'])

@push('styles')
    @vite(['resources/css/weekly-lottery.css'])
@endpush

@section('content')
@php
    $historyPayload = is_array($history ?? null) ? $history : [];
    $rows = is_array($historyPayload['rows'] ?? null) ? $historyPayload['rows'] : [];
    $pagination = is_array($historyPayload['pagination'] ?? null) ? $historyPayload['pagination'] : null;
    $historyStatus = strtolower((string) ($historyPayload['status'] ?? 'no_public_data'));
    $year = $active_year !== null ? (int) $active_year : null;
@endphp
<div class="wl-page" data-wl-page="weekly-lottery-history" data-wl-locale="{{ $meta['lang'] }}">
    <a class="wl-skip-link" href="#wl-main">{{ trans('weekly_lottery.skip_to_content') }}</a>
    <main id="wl-main" class="wl-main" tabindex="-1">
        <header class="wl-header">
            <p class="wl-eyebrow">{{ trans('weekly_lottery.history_heading') }}</p>
            <h1 class="wl-title">{{ trans('weekly_lottery.history_heading') }}</h1>
            <p class="wl-disclaimer">{{ trans('weekly_lottery.not_official_notice') }}</p>
        </header>

        @if ($historyPayload === [] || $historyStatus !== 'result_found')
            <section class="wl-section wl-section--empty" role="status">
                <p class="wl-empty">{{ trans('weekly_lottery.status.'.$historyStatus) }}</p>
            </section>
        @else
            <section class="wl-section" aria-labelledby="weekly-history-heading">
                <h2 id="weekly-history-heading" class="wl-section__heading">
                    {{ trans('weekly_lottery.history_heading') }}@if ($year !== null) — {{ $year }}@endif
                </h2>
                <x-weekly-lottery.result-table :rows="$rows" :is-thai="$is_thai" />
                @if ($pagination !== null && (int) ($pagination['last_page'] ?? 1) > 1 && $year !== null)
                    <nav class="wl-pagination" aria-label="{{ trans('weekly_lottery.history_heading') }}">
                        @if ((bool) ($pagination['has_previous'] ?? false))
                            <a class="wl-pagination__link" rel="prev" href="{{ route('weekly-lottery.history', ['page' => (int) $pagination['page'] - 1]) }}">{{ trans('weekly_lottery.pagination_previous') }}</a>
                        @endif
                        <span class="wl-pagination__status">{{ trans('weekly_lottery.pagination_status', ['page' => (int) ($pagination['page'] ?? 1), 'last' => (int) ($pagination['last_page'] ?? 1)]) }}</span>
                        @if ((bool) ($pagination['has_next'] ?? false))
                            <a class="wl-pagination__link" rel="next" href="{{ route('weekly-lottery.history', ['page' => (int) $pagination['page'] + 1]) }}">{{ trans('weekly_lottery.pagination_next') }}</a>
                        @endif
                    </nav>
                @endif
            </section>
        @endif

        <x-weekly-lottery.year-nav :years="$years" :active-year="$active_year" :is-thai="$is_thai" />
    </main>
</div>
@endsection

@push('scripts')
    @vite(['resources/js/weekly-lottery.js'])
@endpush
```

### `resources/views/weekly-lottery/year.blade.php`
# TYPE: Blade view
# PURPOSE: Weekly year archive surface.

```blade
@extends('layouts.app')

@section('title', $meta['title'])
@section('meta_description', $meta['description'])
@section('meta_canonical', $meta['canonical'])
@section('meta_robots', $meta['robots'])
@section('meta_og_title', $meta['og_title'])
@section('meta_og_description', $meta['og_description'])
@section('meta_og_type', $meta['og_type'])
@section('meta_og_url', $meta['og_url'])

@push('styles')
    @vite(['resources/css/weekly-lottery.css'])
@endpush

@section('content')
@php
    $historyPayload = is_array($history ?? null) ? $history : [];
    $rows = is_array($historyPayload['rows'] ?? null) ? $historyPayload['rows'] : [];
    $pagination = is_array($historyPayload['pagination'] ?? null) ? $historyPayload['pagination'] : null;
    $historyStatus = strtolower((string) ($historyPayload['status'] ?? 'no_public_data'));
    $year = $active_year !== null ? (int) $active_year : null;
@endphp
<div class="wl-page" data-wl-page="weekly-lottery-year" data-wl-locale="{{ $meta['lang'] }}">
    <a class="wl-skip-link" href="#wl-main">{{ trans('weekly_lottery.skip_to_content') }}</a>
    <main id="wl-main" class="wl-main" tabindex="-1">
        <header class="wl-header">
            <p class="wl-eyebrow">{{ trans('weekly_lottery.history_heading') }}</p>
            <h1 class="wl-title">{{ trans('weekly_lottery.history_heading') }}@if ($year !== null) — {{ $year }}@endif</h1>
            <p class="wl-disclaimer">{{ trans('weekly_lottery.not_official_notice') }}</p>
        </header>

        @if ($historyPayload === [] || $historyStatus !== 'result_found')
            <section class="wl-section wl-section--empty" role="status">
                <p class="wl-empty">{{ trans('weekly_lottery.status.'.$historyStatus) }}</p>
            </section>
        @else
            <section class="wl-section" aria-labelledby="weekly-year-heading">
                <h2 id="weekly-year-heading" class="wl-section__heading">{{ trans('weekly_lottery.history_heading') }}</h2>
                <x-weekly-lottery.result-table :rows="$rows" :is-thai="$is_thai" />
                @if ($pagination !== null && (int) ($pagination['last_page'] ?? 1) > 1 && $year !== null)
                    <nav class="wl-pagination" aria-label="{{ trans('weekly_lottery.history_heading') }}">
                        @if ((bool) ($pagination['has_previous'] ?? false))
                            <a class="wl-pagination__link" rel="prev" href="{{ route('weekly-lottery.year', ['year' => $year, 'page' => (int) $pagination['page'] - 1]) }}">{{ trans('weekly_lottery.pagination_previous') }}</a>
                        @endif
                        <span class="wl-pagination__status">{{ trans('weekly_lottery.pagination_status', ['page' => (int) ($pagination['page'] ?? 1), 'last' => (int) ($pagination['last_page'] ?? 1)]) }}</span>
                        @if ((bool) ($pagination['has_next'] ?? false))
                            <a class="wl-pagination__link" rel="next" href="{{ route('weekly-lottery.year', ['year' => $year, 'page' => (int) $pagination['page'] + 1]) }}">{{ trans('weekly_lottery.pagination_next') }}</a>
                        @endif
                    </nav>
                @endif
            </section>
        @endif

        <x-weekly-lottery.year-nav :years="$years" :active-year="$active_year" :is-thai="$is_thai" />
    </main>
</div>
@endsection

@push('scripts')
    @vite(['resources/js/weekly-lottery.js'])
@endpush
```

### `resources/views/weekly-lottery/result-detail.blade.php`
# TYPE: Blade view
# PURPOSE: Weekly result-detail alias surface.

```blade
@extends('layouts.app')

@section('title', $meta['title'])
@section('meta_description', $meta['description'])
@section('meta_canonical', $meta['canonical'])
@section('meta_robots', $meta['robots'])
@section('meta_og_title', $meta['og_title'])
@section('meta_og_description', $meta['og_description'])
@section('meta_og_type', $meta['og_type'])
@section('meta_og_url', $meta['og_url'])

@push('styles')
    @vite(['resources/css/weekly-lottery.css'])
@endpush

@section('content')
@php
    $projection = is_array($current ?? null) ? $current : [];
    $available = (bool) ($projection['available'] ?? false);
    $status = strtolower((string) ($projection['status'] ?? 'unavailable'));
@endphp
<div class="wl-page wl-page--detail" data-wl-page="weekly-lottery-result-detail" data-wl-locale="{{ $meta['lang'] }}">
    <a class="wl-skip-link" href="#wl-main">{{ trans('weekly_lottery.skip_to_content') }}</a>
    <main id="wl-main" class="wl-main" tabindex="-1">
        <header class="wl-header">
            <p class="wl-eyebrow">{{ trans('weekly_lottery.detail_heading') }}</p>
            <h1 class="wl-title">{{ trans('weekly_lottery.detail_heading') }}</h1>
            <p class="wl-disclaimer">{{ trans('weekly_lottery.not_official_notice') }}</p>
        </header>
        <section class="wl-section" aria-labelledby="weekly-result-detail-heading">
            <h2 id="weekly-result-detail-heading" class="wl-section__heading">{{ trans('weekly_lottery.current_result_heading') }}</h2>
            @if (! $available)
                <p class="wl-empty" role="status">{{ trans('weekly_lottery.status.'.$status) }}</p>
            @else
                <x-weekly-lottery.result-card :result="$projection" :is-thai="$is_thai" :show-link="false" />
                <section class="wl-section wl-section--provenance" aria-labelledby="weekly-result-provenance">
                    <h3 id="weekly-result-provenance" class="wl-section__heading">{{ trans('weekly_lottery.provenance_heading') }}</h3>
                    <x-weekly-lottery.source-status :provenance="(array) ($projection['provenance'] ?? [])" :integrity="$projection['integrity'] ?? null" />
                </section>
            @endif
        </section>
        <x-weekly-lottery.year-nav :years="$years" :active-year="$active_year" :is-thai="$is_thai" />
    </main>
</div>
@endsection

@push('scripts')
    @vite(['resources/js/weekly-lottery.js'])
@endpush
```

### `resources/views/components/weekly-lottery/result-card.blade.php`
# TYPE: Blade component
# PURPOSE: Weekly result card using the canonical draw route.

```blade
@props([
    'result' => [],
    'isThai' => false,
    'heading' => null,
    'showLink' => true,
])

@php
    $result = is_array($result) ? $result : [];
    $available = (bool) ($result['available'] ?? false);
    $hasNumbers = (bool) ($result['has_numbers'] ?? $available);
    $status = strtolower((string) ($result['status'] ?? 'unavailable'));
    $draw = is_array($result['draw'] ?? null) ? $result['draw'] : [];
    $date = is_array($draw['date'] ?? null) ? $draw['date'] : [];
    $numbers = is_array($result['numbers'] ?? null) ? $result['numbers'] : [];
    $provenance = is_array($result['provenance'] ?? null) ? $result['provenance'] : [];
    $integrity = is_array($result['integrity'] ?? null) ? $result['integrity'] : null;
    $reference = isset($draw['reference']) ? (string) $draw['reference'] : '';
    $dateLabel = $isThai ? (string) ($date['display_th'] ?? '') : (string) ($date['display_en'] ?? '');
@endphp

<article class="rounded-3xl border border-[#D4AF37]/30 bg-gradient-to-br from-[#1C170E] via-[#141007] to-[#0D0B05] p-6 shadow-2xl sm:p-10" data-wl-card="result" data-wl-status="{{ $status }}">
    <header class="mb-8 flex flex-col justify-between gap-4 border-b border-[#D4AF37]/20 pb-6 sm:flex-row sm:items-start">
        <div><p class="mb-2 text-xs font-bold uppercase tracking-[0.16em] text-[#D4AF37]">{{ trans('weekly_lottery.current_result_heading') }}</p><h2 class="text-2xl font-black text-[#F5E6B8]">{{ $heading ?? trans('weekly_lottery.heading') }}</h2>@if ($dateLabel !== '')<p class="mt-2 text-sm text-gray-300"><time datetime="{{ $date['iso'] ?? '' }}">{{ $dateLabel }}</time></p>@endif @if ($reference !== '')<p class="mt-1 font-mono text-xs text-gray-500">{{ $reference }}</p>@endif</div>
        <x-weekly-lottery.source-status :provenance="$provenance" :integrity="$integrity" compact />
    </header>
    @if (! $available || ! $hasNumbers || $numbers === [])
        <div class="rounded-2xl border border-amber-400/30 bg-amber-400/5 p-6" role="status"><p class="font-semibold text-amber-200">{{ trans('weekly_lottery.status.'.$status) }}</p><p class="mt-2 text-sm leading-7 text-gray-400">{{ trans('weekly_lottery.result_unavailable_explainer') }}</p></div>
    @else
        <div class="grid gap-4 sm:grid-cols-3">
            @foreach (['first_6', 'three_ball', 'two_ball'] as $field)
                @if (is_string($numbers[$field] ?? null) && $numbers[$field] !== '')<div class="rounded-2xl border border-[#D4AF37]/20 bg-[#120F08] p-5" data-wl-field="{{ $field }}"><p class="text-xs font-bold uppercase tracking-wider text-[#D4AF37]">{{ trans('weekly_lottery.field_'.$field) }}</p><p class="mt-3 font-mono text-3xl font-black tracking-[0.16em] text-[#F5E6B8]">{{ $numbers[$field] }}</p><p class="mt-2 text-xs text-gray-500">{{ trans('weekly_lottery.field_'.$field.'_hint') }}</p></div>@endif
            @endforeach
        </div>
        @if ($showLink && $reference !== '')<div class="mt-8 border-t border-[#D4AF37]/15 pt-6"><a class="inline-flex items-center gap-2 rounded-xl border border-[#D4AF37]/40 bg-[#221B0E] px-5 py-3 text-sm font-bold text-[#F5E6B8]" href="{{ route('weekly-lottery.show', ['draw' => $reference]) }}">{{ trans('weekly_lottery.view_detail') }} <span aria-hidden="true">→</span></a></div>@endif
    @endif
</article>
```

### `resources/views/components/weekly-lottery/result-table.blade.php`
# TYPE: Blade component
# PURPOSE: Weekly result table using the canonical draw route.

```blade
@props([
    'rows' => [],
    'isThai' => false,
    'caption' => null,
])
@php $rows = is_array($rows) ? $rows : []; @endphp
<div class="overflow-x-auto rounded-2xl border border-[#D4AF37]/20 bg-[#141007]" data-wl-table="results"><table class="w-full text-left text-sm"><caption class="wl-table__caption">{{ $caption ?? trans('weekly_lottery.table_caption') }}</caption><thead class="border-b border-[#D4AF37]/20 bg-[#1C170E] text-xs uppercase tracking-wider text-[#F5E6B8]"><tr><th scope="col" class="px-4 py-4">{{ trans('weekly_lottery.col_draw_date') }}</th><th scope="col" class="px-4 py-4">{{ trans('weekly_lottery.col_first_6') }}</th><th scope="col" class="px-4 py-4">{{ trans('weekly_lottery.col_three_ball') }}</th><th scope="col" class="px-4 py-4">{{ trans('weekly_lottery.col_two_ball') }}</th><th scope="col" class="px-4 py-4">{{ trans('weekly_lottery.col_source') }}</th><th scope="col" class="px-4 py-4">{{ trans('weekly_lottery.col_detail') }}</th></tr></thead><tbody class="divide-y divide-white/5 text-gray-300">@forelse ($rows as $row) @php $draw = is_array($row['draw'] ?? null) ? $row['draw'] : []; $date = is_array($draw['date'] ?? null) ? $draw['date'] : []; $numbers = is_array($row['numbers'] ?? null) ? $row['numbers'] : []; $source = is_array($row['provenance'] ?? null) ? $row['provenance'] : []; $ref = (string) ($draw['reference'] ?? ''); $label = $isThai ? (string) ($date['display_th'] ?? '') : (string) ($date['display_en'] ?? ''); @endphp<tr class="hover:bg-white/[0.03]"><th scope="row" class="whitespace-nowrap px-4 py-4 font-semibold text-white" data-label="{{ trans('weekly_lottery.col_draw_date') }}">{{ $label }}</th><td class="px-4 py-4 font-mono" data-label="{{ trans('weekly_lottery.col_first_6') }}" data-wl-field="first_6">{{ is_string($numbers['first_6'] ?? null) && $numbers['first_6'] !== '' ? $numbers['first_6'] : trans('weekly_lottery.no_value') }}</td><td class="px-4 py-4 font-mono" data-label="{{ trans('weekly_lottery.col_three_ball') }}" data-wl-field="three_ball">{{ is_string($numbers['three_ball'] ?? null) && $numbers['three_ball'] !== '' ? $numbers['three_ball'] : trans('weekly_lottery.no_value') }}</td><td class="px-4 py-4 font-mono" data-label="{{ trans('weekly_lottery.col_two_ball') }}" data-wl-field="two_ball">{{ is_string($numbers['two_ball'] ?? null) && $numbers['two_ball'] !== '' ? $numbers['two_ball'] : trans('weekly_lottery.no_value') }}</td><td class="px-4 py-4 text-xs">{{ trans('weekly_lottery.source_state.'.strtolower((string) ($source['source_state'] ?? 'UNAVAILABLE'))) }}</td><td class="px-4 py-4">@if ($ref !== '')<a class="text-[#D4AF37] hover:underline" href="{{ route('weekly-lottery.show', ['draw' => $ref]) }}">{{ trans('weekly_lottery.view_detail') }}</a>@else<span>{{ trans('weekly_lottery.no_value') }}</span>@endif</td></tr>@empty<tr><td colspan="6" class="px-4 py-10 text-center text-gray-400">{{ trans('weekly_lottery.empty_year') }}</td></tr>@endforelse</tbody></table></div>
```

### `tests/Feature/Lottery/Pages25To34Test.php`
# TYPE: PHP test
# PURPOSE: Focused route, legacy, fail-closed purchase, no-static-speed-data, and localization parity checks.

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Lottery;

use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Route and source-surface checks for Pages 25–34.
 *
 * These checks deliberately do not seed a result or invent a product purchase
 * contract. Result values remain the responsibility of the existing Weekly and
 * Bingo/Mega services and their public projections.
 */
final class Pages25To34Test extends TestCase
{
    /**
     * @dataProvider pageRouteProvider
     */
    public function test_each_page_has_an_independent_public_route(string $path, string $expectedName): void
    {
        $route = app('router')->getRoutes()->match(Request::create($path, 'GET'));

        $this->assertSame($expectedName, $route->getName());
        $this->assertContains('public.legal', $route->gatherMiddleware());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function pageRouteProvider(): array
    {
        return [
            '25 weekly draw detail' => ['/weekly-lottery/draw/EXAMPLE-DRAW', 'weekly-lottery.draw'],
            '26 weekly latest result' => ['/weekly-lottery/latest', 'weekly-lottery.latest'],
            '27 weekly historical results' => ['/weekly-lottery/history', 'weekly-lottery.history'],
            '28 weekly year archive' => ['/weekly-lottery/archive/2026', 'weekly-lottery.archive'],
            '29 weekly result detail' => ['/weekly-lottery/result/EXAMPLE-DRAW', 'weekly-lottery.result'],
            '30 mega lottery' => ['/bingo-lottery', 'bingo-lottery.index'],
            '31 mega purchase page' => ['/bingo-lottery/buy', 'bingo-lottery.buy'],
            '32 mega draw detail' => ['/bingo-lottery/draw/EXAMPLE-DRAW', 'bingo-lottery.draw'],
            '33 mega latest result' => ['/bingo-lottery/latest', 'bingo-lottery.latest'],
            '34 mega historical results' => ['/bingo-lottery/history', 'bingo-lottery.history'],
        ];
    }

    public function test_mega_pages_remain_in_the_approved_bingo_route_family(): void
    {
        $routes = app('router')->getRoutes();
        $paths = [];

        foreach ($routes as $route) {
            $uri = '/'.ltrim($route->uri(), '/');

            if (str_starts_with(ltrim($uri, '/'), 'bingo-lottery')) {
                $paths[] = ltrim($uri, '/');
            }
        }

        $this->assertContains('bingo-lottery', $paths);
        $this->assertNotContains('mega-lottery', $paths);
    }

    public function test_legacy_mega_path_still_resolves_to_the_canonical_index(): void
    {
        $response = $this->get('/bingo-lottery.php');

        $response->assertRedirect(route('bingo-lottery.index'));
        $this->assertSame(301, $response->getStatusCode());
    }

    public function test_mega_landing_surface_contains_no_static_speed_round_claims(): void
    {
        $content = (string) file_get_contents(resource_path('views/bingo-lottery/index.blade.php'));

        foreach (['Round #68', 'BINGO-2026-R67', '50,000', '04', '28', '17:00:00', '฿50,000', '฿900', '฿95'] as $unsupportedValue) {
            $this->assertStringNotContainsString($unsupportedValue, $content);
        }

        $this->assertStringContainsString("x-bingo-lottery.result-card", $content);
        $this->assertStringContainsString("route('bingo-lottery.buy')", $content);
    }

    public function test_mega_purchase_surface_is_fail_closed(): void
    {
        $content = (string) file_get_contents(resource_path('views/bingo-lottery/buy.blade.php'));

        $this->assertStringContainsString("purchase_not_configured", $content);
        $this->assertStringContainsString("NOT_CONFIGURED", (string) file_get_contents(lang_path('en/bingo_lottery.php')));
        $this->assertStringNotContainsString('price', strtolower($content));
        $this->assertStringNotContainsString('wallet', strtolower($content));
        $this->assertStringNotContainsString('purchase success', strtolower($content));
    }

    public function test_mega_translation_files_have_matching_top_level_keys(): void
    {
        $english = require lang_path('en/bingo_lottery.php');
        $thai = require lang_path('th/bingo_lottery.php');

        $this->assertSame(array_keys($english), array_keys($thai));
        $this->assertSame(array_keys($english['search_type']), array_keys($thai['search_type']));
        $this->assertSame(array_keys($english['provenance']), array_keys($thai['provenance']));
        $this->assertSame(array_keys($english['integrity']), array_keys($thai['integrity']));
        $this->assertSame(array_keys($english['integrity_status']), array_keys($thai['integrity_status']));
        $this->assertSame(array_keys($english['integrity_hint']), array_keys($thai['integrity_hint']));
        $this->assertSame(array_keys($english['source_state']), array_keys($thai['source_state']));
        $this->assertSame(array_keys($english['source_state_hint']), array_keys($thai['source_state_hint']));
        $this->assertSame(array_keys($english['status']), array_keys($thai['status']));
    }
}
```

