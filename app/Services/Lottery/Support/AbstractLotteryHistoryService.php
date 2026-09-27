<?php

declare(strict_types=1);

namespace App\Services\Lottery\Support;

use App\Lottery\Schema\LaneSchema;
use App\Lottery\Schema\LaneSchemaFactory;
use App\Models\Support\AbstractLotteryDraw;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Historical Weekly results, year navigation and pagination.
 *
 * THE YEAR LIST IS A QUERY, NOT A CONSTANT
 * ---------------------------------------------------------------------------
 * availableYears() runs SELECT DISTINCT draw_year over the publicly visible
 * draws. There is no array of years anywhere in this lane - not 2568, not
 * 2567, not a range built from the current year. That matters twice over. A
 * hard-coded year with no data renders a navigation link to an empty page,
 * which reads to a visitor as "the results are missing" rather than "we never
 * had them". And a hard-coded list silently stops being true every January.
 *
 * A year with no published draws is therefore never offered. If someone types
 * one into the URL anyway, the page renders NO_PUBLIC_DATA - an empty state
 * that says so - rather than a success page with an empty table, which would
 * look like a result set of zero draws.
 *
 * DRAWS WITH NO NUMBERS STILL APPEAR IN HISTORY
 * ---------------------------------------------------------------------------
 * A published draw whose numbers are unavailable is a real row in the table
 * with its three cells marked "not published". Hiding it would misrepresent
 * the record: the draw happened, and the honest history shows the gap instead
 * of closing it.
 *
 * MEMORY AND N+1
 * ---------------------------------------------------------------------------
 * History paginates at the database with a bounded per-page, and every page
 * eager-loads its two relations in one extra query each. Nothing here calls
 * ->get() on an unbounded query or ->all() on the draw table, so a year with
 * 1,000 draws costs the same memory as a year with 10.
 *
 * CACHING
 * ---------------------------------------------------------------------------
 * Keys are weekly-lottery.history.{year}.{page}.{version}. The {version}
 * component is the lane's publication counter for that year - the highest
 * current_result_version_id among its public draws - so publishing a
 * correction anywhere in a year rotates the key for every page of that year at
 * once. That is the cheapest correct invalidation available: the default cache
 * store here is the database driver, which has no tag support, and no page may
 * be left holding superseded numbers.
 */
abstract class AbstractLotteryHistoryService
{
    use AppliesLaneOrdering;

    public function __construct(
        protected readonly ConfigRepository $config,
        protected readonly CacheRepository $cache,
        protected readonly AbstractLotteryCalendarService $dates,
        protected readonly AbstractLotteryResultService $results,
    ) {}

    /**
     * The config file this lane reads, e.g. 'weekly_lottery'.
     *
     * Every config lookup in this class is relative to it, so a subclass
     * cannot accidentally read another lane's page size, cache TTL or search
     * map.
     */
    abstract protected function configPrefix(): string;

    /**
     * This lane's schema: its fields, their widths, which of them are
     * searchable, and whether a date alone identifies a draw.
     *
     * Built from the lane's config file, so the engine never has to be told
     * the same fact twice.
     */
    protected function schema(): LaneSchema
    {
        return app(LaneSchemaFactory::class)->for($this->configPrefix());
    }

    /**
     * Years that actually have publicly visible draws, newest first.
     *
     * @return list<array{gregorian: int, buddhist: int, label_en: string, label_th: string, draws: int}>
     */
    public function availableYears(): array
    {
        $key = $this->prefix().'.years';

        $rows = $this->cacheEnabled()
            ? $this->cache->remember($key, $this->ttl(), fn (): array => $this->queryYears())
            : $this->queryYears();

        return is_array($rows) ? $rows : [];
    }

    public function hasYear(int $gregorianYear): bool
    {
        foreach ($this->availableYears() as $year) {
            if ($year['gregorian'] === $gregorianYear) {
                return true;
            }
        }

        return false;
    }

    public function latestYear(): ?int
    {
        $years = $this->availableYears();

        return $years === [] ? null : $years[0]['gregorian'];
    }

    /**
     * One page of history for one year.
     *
     * Returns a plain array so the result can be cached and so a template
     * never has to know whether it holds a paginator or a cache hit.
     *
     * @return array{
     *     status: string,
     *     year: array{gregorian: int, buddhist: int}|null,
     *     rows: list<array<string, mixed>>,
     *     pagination: array{page: int, per_page: int, total: int, last_page: int, has_previous: bool, has_next: bool}
     * }
     */
    public function historyForYear(int $gregorianYear, int $page, ?int $perPage = null): array
    {
        // Bounds first, query second. An out-of-range year never becomes SQL.
        if (! $this->dates->isAcceptableYear($gregorianYear)) {
            return $this->emptyHistory('INVALID_QUERY', null, $page, $this->perPage($perPage));
        }

        $page = $this->boundedPage($page);
        $perPage = $this->perPage($perPage);

        if (! $this->hasYear($gregorianYear)) {
            return $this->emptyHistory('NO_PUBLIC_DATA', $gregorianYear, $page, $perPage);
        }

        // ONLY THE DEFAULT PAGE SIZE IS CACHED, and the reason is a real bug
        // this guard closes: the specified key format is
        // history.{year}.{page}.{version}, which does not mention per_page.
        // Caching a 50-row page under that key and then serving it to a caller
        // who asked for 2 rows returns fifty. Rather than widening the key
        // away from the specified format, an API caller who asks for a
        // non-default size gets a fresh, uncached query - the HTML pages,
        // which are the traffic that matters, always use the default.
        if (! $this->cacheEnabled() || $perPage !== $this->perPage(null)) {
            return $this->queryHistory($gregorianYear, $page, $perPage);
        }

        $key = $this->historyCacheKey($gregorianYear, $page, $this->yearVersionStamp($gregorianYear));

        $payload = $this->cache->remember(
            $key,
            $this->ttl(),
            fn (): array => $this->queryHistory($gregorianYear, $page, $perPage),
        );

        return is_array($payload) ? $payload : $this->queryHistory($gregorianYear, $page, $perPage);
    }

    /**
     * The recent-draws strip under the current result on the landing page.
     *
     * @return list<array<string, mixed>>
     */
    public function recentDraws(?string $excludeReference = null): array
    {
        $limit = $this->config->get($this->configPrefix().'.page.recent_rows');
        $limit = is_int($limit) && $limit > 0 ? min($limit, 50) : 12;

        $query = $this->results->publiclyVisibleQuery()
            ->with($this->results->publicRelations())
            ->tap(fn ($q) => $this->applyNewestFirst($q))
            ->limit($limit);

        if (is_string($excludeReference) && $excludeReference !== '') {
            $query->where('draw_reference', '!=', $excludeReference);
        }

        $rows = [];

        foreach ($query->get() as $draw) {
            $rows[] = $this->results->projectDraw($draw);
        }

        return $rows;
    }

    /** <lane>.history.{year}.{page}.{version} */
    public function historyCacheKey(int $year, int $page, int $version): string
    {
        return $this->prefix().'.history.'.$year.'.'.$page.'.'.$version;
    }

    /**
     * Invalidate the year index after a publication.
     *
     * Only the DISTINCT-years list needs an explicit forget: page keys carry
     * the year's version stamp and rotate on their own.
     */
    public function forgetYearIndex(): void
    {
        $this->cache->forget($this->prefix().'.years');
    }

    /**
     * @return list<array{gregorian: int, buddhist: int, label_en: string, label_th: string, draws: int}>
     */
    protected function queryYears(): array
    {
        $maxYears = $this->config->get($this->configPrefix().'.page.max_years_listed');
        $maxYears = is_int($maxYears) && $maxYears > 0 ? $maxYears : 40;

        $rows = $this->results->publiclyVisibleQuery()
            ->getQuery()
            ->select('draw_year')
            ->selectRaw('COUNT(*) as draw_count')
            ->groupBy('draw_year')
            ->orderByDesc('draw_year')
            ->limit($maxYears)
            ->get();

        $years = [];

        foreach ($rows as $row) {
            $gregorian = (int) $row->draw_year;

            $years[] = [
                'gregorian' => $gregorian,
                'buddhist' => $this->dates->toBuddhistYear($gregorian),
                'label_en' => (string) $gregorian,
                'label_th' => (string) $this->dates->toBuddhistYear($gregorian),
                'draws' => (int) $row->draw_count,
            ];
        }

        return $years;
    }

    /**
     * @return array{
     *     status: string,
     *     year: array{gregorian: int, buddhist: int}|null,
     *     rows: list<array<string, mixed>>,
     *     pagination: array{page: int, per_page: int, total: int, last_page: int, has_previous: bool, has_next: bool}
     * }
     */
    protected function queryHistory(int $gregorianYear, int $page, int $perPage): array
    {
        /** @var LengthAwarePaginator<int, AbstractLotteryDraw> $paginator */
        $paginator = $this->results->publiclyVisibleQuery()
            ->where('draw_year', $gregorianYear)
            ->with($this->results->publicRelations())
            ->tap(fn ($q) => $this->applyNewestFirst($q))
            ->paginate(perPage: $perPage, page: $page);

        $rows = [];

        foreach ($paginator->items() as $draw) {
            $rows[] = $this->results->projectDraw($draw);
        }

        if ($rows === []) {
            return $this->emptyHistory('NO_PUBLIC_DATA', $gregorianYear, $page, $perPage);
        }

        return [
            'status' => 'RESULT_FOUND',
            'year' => [
                'gregorian' => $gregorianYear,
                'buddhist' => $this->dates->toBuddhistYear($gregorianYear),
            ],
            'rows' => $rows,
            'pagination' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'has_previous' => $paginator->currentPage() > 1,
                'has_next' => $paginator->hasMorePages(),
            ],
        ];
    }

    /**
     * The highest published version id within a year.
     *
     * The {version} component of the history cache key. A correction published
     * for any draw in the year raises it, which rotates every page key for
     * that year in one step.
     */
    protected function yearVersionStamp(int $gregorianYear): int
    {
        $max = $this->results->publiclyVisibleQuery()
            ->where('draw_year', $gregorianYear)
            ->max('current_result_version_id');

        return is_numeric($max) ? (int) $max : 0;
    }

    /**
     * @return array{
     *     status: string,
     *     year: array{gregorian: int, buddhist: int}|null,
     *     rows: list<array<string, mixed>>,
     *     pagination: array{page: int, per_page: int, total: int, last_page: int, has_previous: bool, has_next: bool}
     * }
     */
    protected function emptyHistory(string $status, ?int $gregorianYear, int $page, int $perPage): array
    {
        return [
            'status' => $status,
            'year' => $gregorianYear === null ? null : [
                'gregorian' => $gregorianYear,
                'buddhist' => $this->dates->toBuddhistYear($gregorianYear),
            ],
            'rows' => [],
            'pagination' => [
                'page' => $this->boundedPage($page),
                'per_page' => $perPage,
                'total' => 0,
                'last_page' => 1,
                'has_previous' => false,
                'has_next' => false,
            ],
        ];
    }

    /**
     * Page numbers are clamped. 'page=99999999' costs one comparison, not a
     * deep OFFSET scan.
     */
    protected function boundedPage(int $page): int
    {
        return max(1, min($page, 10000));
    }

    protected function perPage(?int $requested): int
    {
        $default = $this->config->get($this->configPrefix().'.page.per_page');
        $default = is_int($default) && $default > 0 ? $default : 20;

        $max = $this->config->get($this->configPrefix().'.page.max_per_page');
        $max = is_int($max) && $max > 0 ? $max : 50;

        if ($requested === null || $requested < 1) {
            return min($default, $max);
        }

        return min($requested, $max);
    }

    protected function cacheEnabled(): bool
    {
        return (bool) $this->config->get($this->configPrefix().'.cache.enabled', true);
    }

    protected function ttl(): int
    {
        $ttl = $this->config->get($this->configPrefix().'.cache.ttl_seconds');

        return is_int($ttl) && $ttl > 0 ? $ttl : 300;
    }

    protected function prefix(): string
    {
        $prefix = $this->config->get($this->configPrefix().'.cache.key_prefix');
        $prefix = is_string($prefix) && $prefix !== ''
            ? $prefix
            : str_replace('_', '-', $this->configPrefix());

        $projection = $this->config->get($this->configPrefix().'.projection_version');
        $projection = is_string($projection) && $projection !== '' ? $projection : '1';

        return $prefix.'.v'.$projection;
    }
}
