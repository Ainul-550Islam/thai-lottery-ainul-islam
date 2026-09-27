<?php

declare(strict_types=1);

namespace App\Services\Lottery\Support;

use App\Lottery\Schema\LaneSchema;
use App\Lottery\Schema\LaneSchemaFactory;
use App\Models\Support\AbstractLotteryDraw;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Builder;

/**
 * Public search over published Weekly Lottery results.
 *
 * THE TYPE IS EXPLICIT. IT IS NEVER GUESSED FROM LENGTH.
 * ---------------------------------------------------------------------------
 * The National lane matches a term against every field of the same width,
 * which is reasonable there. Here the brief is stricter and the reason is
 * good: with only three fields, a two-digit term could plausibly be a 2Ball or
 * the tail of something else, and answering "found in 2Ball" when the visitor
 * meant something else is the page asserting a match it was not asked for. So
 * the caller states '6ball', '3ball', '2ball' or 'date', the value is checked
 * against a CLOSED whitelist from config, and an unrecognised type is refused
 * before a query exists.
 *
 * EXACT MATCHING ONLY. There is no LIKE, no wildcard and no fuzzy comparison
 * anywhere in this file. '09' matches the two-character string '09' and
 * nothing else - not '9', not '090', not '109'. A fuzzy lottery match is a
 * page telling someone they won when they did not.
 *
 * EVERY INPUT IS SHAPE-CHECKED BEFORE IT BECOMES SQL
 * ---------------------------------------------------------------------------
 * A number must match the exact pattern for its declared type, and the column
 * name comes from the config map rather than from the request. No user string
 * ever reaches a column name, a table name, an operator or an ORDER BY clause.
 * Values travel as bindings; there is no raw SQL and no string interpolation
 * in this file.
 *
 * LEADING ZEROS SURVIVE THE SEARCH BOX
 * ---------------------------------------------------------------------------
 * The term stays a string from the request to the binding. Nothing here calls
 * intval(), (int), ltrim($n, '0') or a number formatter, so searching 049 does
 * not quietly become a search for 49 - which would return the wrong draw, or
 * none, and look like missing data.
 *
 * SEARCH IS NOT CACHED
 * ---------------------------------------------------------------------------
 * Deliberate, and configured (cache.cache_search = false). A cache keyed on
 * attacker-chosen input is a memory that makes enumeration cheaper the second
 * time. The rate limiter, not the cache, absorbs search volume.
 *
 * ONLY PUBLIC RESULT DATA IS READ
 * ---------------------------------------------------------------------------
 * Every query starts from WeeklyLotteryResultService::publiclyVisibleQuery().
 * No ticket, user, claim, freeze or dealer table is referenced in this file,
 * so a public search cannot confirm the existence of a private record. That is
 * stronger than filtering such data out afterwards.
 */
abstract class AbstractLotterySearchService
{
    use AppliesLaneOrdering;

    public function __construct(
        protected readonly ConfigRepository $config,
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
     * The only column names this lane's search may ever touch.
     *
     * Comes from the schema, which is also what validates widths on import, so
     * the two can never disagree about what a field is.
     *
     * PROMPT 8 shipped this as a hand-written list on each lane subclass and
     * PROMPT 9 moved it here. A per-lane list is a per-lane thing to forget,
     * and the first time it was forgotten every search in the new lane
     * returned INVALID_QUERY.
     *
     * @return list<string>
     */
    protected function searchableColumns(): array
    {
        return $this->schema()->searchableColumns();
    }

    /**
     * The closed list of accepted search types.
     *
     * @return list<string>
     */
    public function searchTypes(): array
    {
        $types = $this->config->get($this->configPrefix().'.search.types');

        if (! is_array($types) || $types === []) {
            return ['6ball', '3ball', '2ball', 'date'];
        }

        $out = [];

        foreach ($types as $type) {
            if (is_string($type) && $type !== '') {
                $out[] = $type;
            }
        }

        return $out === [] ? ['6ball', '3ball', '2ball', 'date'] : $out;
    }

    /**
     * Run one search.
     *
     * @return array{
     *     status: string,
     *     query: array{type: string|null, term: string|null},
     *     matches: list<array<string, mixed>>,
     *     matched_field: string|null,
     *     pagination: array{page: int, per_page: int, total: int, last_page: int, has_previous: bool, has_next: bool}
     * }
     */
    public function search(string $type, string $term, int $page = 1): array
    {
        $type = trim($type);
        $term = trim($term);

        if (! in_array($type, $this->searchTypes(), true)) {
            // Rejected against the whitelist, never passed through.
            return $this->emptySearch('INVALID_QUERY', null, null, $page);
        }

        if ($term === '' || strlen($term) > $this->maxTermLength()) {
            return $this->emptySearch('INVALID_QUERY', $type, null, $page);
        }

        return $type === 'date'
            ? $this->searchByDate($term, $page)
            : $this->searchByNumber($type, $term, $page);
    }

    /**
     * Number search for one declared field.
     *
     * @return array{
     *     status: string,
     *     query: array{type: string|null, term: string|null},
     *     matches: list<array<string, mixed>>,
     *     matched_field: string|null,
     *     pagination: array{page: int, per_page: int, total: int, last_page: int, has_previous: bool, has_next: bool}
     * }
     */
    public function searchByNumber(string $type, string $term, int $page = 1): array
    {
        $meta = $this->typeMeta($type);

        if ($meta === null) {
            return $this->emptySearch('INVALID_QUERY', null, null, $page);
        }

        // Exact width, digits only. '9' is not a 2Ball and is refused rather
        // than widened into '09'.
        if (preg_match($meta['pattern'], $term) !== 1) {
            return $this->emptySearch('INVALID_QUERY', $type, $term, $page);
        }

        $column = $meta['column'];

        $query = $this->results->publiclyVisibleQuery()
            ->whereHas('results', function (Builder $inner) use ($column, $term): void {
                // Column name comes from the config map; the value is a
                // binding. Exact equality - no LIKE, no wildcard.
                $inner->where('is_current', true)
                    ->where('result_status', AbstractLotteryDraw::RESULT_PUBLISHED)
                    ->where($column, '=', $term);
            });

        return $this->paginate($query, $type, $term, $column, $page);
    }

    /**
     * Date search.
     *
     * The string is parsed by the shared calendar service against a closed
     * format list; an unparseable value returns INVALID_QUERY without a query
     * being built.
     *
     * @return array{
     *     status: string,
     *     query: array{type: string|null, term: string|null},
     *     matches: list<array<string, mixed>>,
     *     matched_field: string|null,
     *     pagination: array{page: int, per_page: int, total: int, last_page: int, has_previous: bool, has_next: bool}
     * }
     */
    public function searchByDate(string $input, int $page = 1): array
    {
        $date = $this->dates->parsePublicDate($input);

        if ($date === null) {
            return $this->emptySearch('INVALID_QUERY', 'date', null, $page);
        }

        $iso = $this->dates->toIsoDate($date);

        $query = $this->results->publiclyVisibleQuery()
            ->where('draw_date', '=', $iso);

        return $this->paginate($query, 'date', $iso, 'draw_date', $page);
    }

    /**
     * Longest public search term accepted, exposed so the request validator
     * and this service cannot disagree.
     */
    public function maxTermLength(): int
    {
        $max = $this->config->get($this->configPrefix().'.page.max_search_length');

        return is_int($max) && $max > 0 ? min($max, 64) : 32;
    }

    /**
     * @return array{column: string, length: int, pattern: string}|null
     */
    protected function typeMeta(string $type): ?array
    {
        $map = $this->config->get($this->configPrefix().'.search.type_map');

        if (! is_array($map) || ! isset($map[$type]) || ! is_array($map[$type])) {
            return null;
        }

        $meta = $map[$type];

        $column = $meta['column'] ?? null;
        $length = $meta['length'] ?? null;
        $pattern = $meta['pattern'] ?? null;

        if (! is_string($column) || ! is_int($length) || ! is_string($pattern)) {
            return null;
        }

        // Second gate: even a tampered config cannot name a column that is not
        // one of THIS lane's public result fields. The list comes from the
        // lane's result model, so it can never drift from the table, and a
        // config edit cannot introduce a column name of its own.
        if (! in_array($column, $this->searchableColumns(), true)) {
            return null;
        }

        return ['column' => $column, 'length' => $length, 'pattern' => $pattern];
    }

    /**
     * @param  Builder<AbstractLotteryDraw>  $query
     * @return array{
     *     status: string,
     *     query: array{type: string|null, term: string|null},
     *     matches: list<array<string, mixed>>,
     *     matched_field: string|null,
     *     pagination: array{page: int, per_page: int, total: int, last_page: int, has_previous: bool, has_next: bool}
     * }
     */
    protected function paginate(
        Builder $query,
        string $type,
        string $term,
        string $matchedField,
        int $page,
    ): array {
        $page = max(1, min($page, 10000));

        $paginator = $query
            ->with($this->results->publicRelations())
            ->tap(fn ($q) => $this->applyNewestFirst($q))
            ->paginate(perPage: $this->perPage(), page: $page);

        $matches = [];

        foreach ($paginator->items() as $draw) {
            if ($draw instanceof AbstractLotteryDraw) {
                $matches[] = $this->results->projectDraw($draw);
            }
        }

        return [
            'status' => $matches === [] ? 'RESULT_NOT_FOUND' : 'RESULT_FOUND',
            'query' => ['type' => $type, 'term' => $term],
            'matches' => $matches,
            'matched_field' => $matches === [] ? null : $matchedField,
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
     * @return array{
     *     status: string,
     *     query: array{type: string|null, term: string|null},
     *     matches: list<array<string, mixed>>,
     *     matched_field: string|null,
     *     pagination: array{page: int, per_page: int, total: int, last_page: int, has_previous: bool, has_next: bool}
     * }
     */
    protected function emptySearch(string $status, ?string $type, ?string $term, int $page): array
    {
        $allowed = $this->config->get($this->configPrefix().'.public_lookup_states');
        $status = is_array($allowed) && in_array($status, $allowed, true) ? $status : 'UNAVAILABLE';

        return [
            'status' => $status,
            'query' => ['type' => $type, 'term' => $term],
            'matches' => [],
            'matched_field' => null,
            'pagination' => [
                'page' => max(1, min($page, 10000)),
                'per_page' => $this->perPage(),
                'total' => 0,
                'last_page' => 1,
                'has_previous' => false,
                'has_next' => false,
            ],
        ];
    }

    protected function perPage(): int
    {
        $default = $this->config->get($this->configPrefix().'.page.per_page');
        $default = is_int($default) && $default > 0 ? $default : 20;

        $max = $this->config->get($this->configPrefix().'.page.max_per_page');
        $max = is_int($max) && $max > 0 ? $max : 50;

        return min($default, $max);
    }
}
