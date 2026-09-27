<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Models\NationalLotteryDraw;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Builder;

/**
 * Public search over published National Lottery results.
 *
 * WHAT MAY BE SEARCHED, AND WHAT MAY NOT
 * ---------------------------------------------------------------------------
 * A six-digit number, a date, or one of the short fields (3Up, 2Up, 3Front,
 * 3After, 2Down). That is the whole surface. This service reads ONLY
 * national_lottery_* tables restricted to publicly visible draws, so a public
 * search can never confirm the existence of a private ticket, a claim, a
 * freeze, a dealer or a user. There is no branch here that touches those
 * tables, which is stronger than filtering them out afterwards.
 *
 * EVERY INPUT IS SHAPE-CHECKED BEFORE IT BECOMES SQL
 * ---------------------------------------------------------------------------
 * A number must match /^[0-9]{2,6}$/ and a field name must be a key of a
 * const map. Both are validated against literals, so no user string ever
 * reaches a column name, a table name, an operator or an ORDER BY clause.
 * Values travel as bindings. There is no raw SQL and no string interpolation
 * anywhere in this file.
 *
 * LEADING ZEROS SURVIVE THE SEARCH BOX
 * ---------------------------------------------------------------------------
 * The query term stays a string from the request to the binding. '004615' is
 * matched against a CHAR(6) column as '004615'. Nothing here calls intval(),
 * (int), ltrim($n, '0') or number formatting, so searching 004615 does not
 * quietly become a search for 4615 - which would return the wrong draw, or
 * none, and look like missing data.
 *
 * SEARCH IS NOT CACHED
 * ---------------------------------------------------------------------------
 * Deliberate, and configured (cache.cache_search = false). A cache keyed on
 * attacker-chosen input is a memory that makes enumeration cheaper the second
 * time. The rate limiter, not the cache, is what absorbs search volume.
 *
 * RESULTS ARE BOUNDED
 * ---------------------------------------------------------------------------
 * Every search paginates with the same bounded per-page as the history pages,
 * so a term that matches thousands of rows returns one page, not a table
 * dump.
 */
class NationalLotterySearchService
{
    /**
     * The ONLY searchable fields, mapped to their column and exact width.
     * A field name arriving from a request is looked up here or rejected; it
     * is never concatenated into a query.
     *
     * @var array<string, array{column: string, length: int}>
     */
    private const SEARCHABLE_SCALARS = [
        'first_prize' => ['column' => 'first_prize', 'length' => 6],
        'three_up' => ['column' => 'three_up', 'length' => 3],
        'two_up' => ['column' => 'two_up', 'length' => 2],
        'two_down' => ['column' => 'two_down', 'length' => 2],
    ];

    /**
     * List fields live in JSON columns and are matched by whole-value
     * containment, never by LIKE '%value%' - which would match '123' inside
     * '512' and report a win that did not happen.
     *
     * @var array<string, array{column: string, length: int}>
     */
    private const SEARCHABLE_LISTS = [
        'three_front' => ['column' => 'three_front', 'length' => 3],
        'three_after' => ['column' => 'three_after', 'length' => 3],
    ];

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly NationalLotteryDateService $dates,
        private readonly NationalLotteryResultService $results,
    ) {}

    /**
     * @return list<string>
     */
    public static function searchableFields(): array
    {
        return array_merge(array_keys(self::SEARCHABLE_SCALARS), array_keys(self::SEARCHABLE_LISTS));
    }

    /**
     * Search by number.
     *
     * When $field is null the number is matched against EVERY field whose
     * width equals the term's length. A six-digit term therefore searches the
     * first prize; a three-digit term searches 3Up, 3Front and 3After; a
     * two-digit term searches 2Up and 2Down. Matching by width rather than by
     * guessing intent means the page never has to claim a number "is" a 3Up
     * when the visitor only said "123".
     *
     * @return array{
     *     status: string,
     *     query: array{type: string, term: string|null, field: string|null},
     *     matches: list<array<string, mixed>>,
     *     matched_fields: list<string>,
     *     pagination: array{page: int, per_page: int, total: int, last_page: int, has_previous: bool, has_next: bool}
     * }
     */
    public function searchByNumber(string $number, ?string $field = null, int $page = 1): array
    {
        $number = trim($number);

        if (preg_match('/^[0-9]{2,6}$/', $number) !== 1) {
            return $this->emptySearch('INVALID_QUERY', 'number', $number === '' ? null : '', $field, $page);
        }

        $width = strlen($number);

        $scalars = [];
        $lists = [];

        if ($field !== null) {
            if (isset(self::SEARCHABLE_SCALARS[$field])) {
                if (self::SEARCHABLE_SCALARS[$field]['length'] !== $width) {
                    return $this->emptySearch('INVALID_QUERY', 'number', $number, $field, $page);
                }
                $scalars[$field] = self::SEARCHABLE_SCALARS[$field];
            } elseif (isset(self::SEARCHABLE_LISTS[$field])) {
                if (self::SEARCHABLE_LISTS[$field]['length'] !== $width) {
                    return $this->emptySearch('INVALID_QUERY', 'number', $number, $field, $page);
                }
                $lists[$field] = self::SEARCHABLE_LISTS[$field];
            } else {
                // Unknown field name: rejected against the const map, never
                // passed through to a query.
                return $this->emptySearch('INVALID_QUERY', 'number', $number, null, $page);
            }
        } else {
            foreach (self::SEARCHABLE_SCALARS as $name => $meta) {
                if ($meta['length'] === $width) {
                    $scalars[$name] = $meta;
                }
            }

            foreach (self::SEARCHABLE_LISTS as $name => $meta) {
                if ($meta['length'] === $width) {
                    $lists[$name] = $meta;
                }
            }
        }

        if ($scalars === [] && $lists === []) {
            return $this->emptySearch('INVALID_QUERY', 'number', $number, $field, $page);
        }

        $query = $this->results->publiclyVisibleQuery()
            ->whereHas('results', function (Builder $inner) use ($scalars, $lists, $number): void {
                $inner->where('is_current', true)
                    ->where(function (Builder $any) use ($scalars, $lists, $number): void {
                        foreach ($scalars as $meta) {
                            // Column name comes from the const map; the value
                            // is a binding.
                            $any->orWhere($meta['column'], '=', $number);
                        }

                        foreach ($lists as $meta) {
                            $any->orWhereJsonContains($meta['column'], $number);
                        }
                    });
            });

        return $this->paginate(
            $query,
            'number',
            $number,
            $field,
            $page,
            array_merge(array_keys($scalars), array_keys($lists)),
        );
    }

    /**
     * Search by date.
     *
     * The string is parsed by NationalLotteryDateService against a closed
     * format list; an unparseable value returns INVALID_QUERY without a query
     * being built.
     *
     * @return array{
     *     status: string,
     *     query: array{type: string, term: string|null, field: string|null},
     *     matches: list<array<string, mixed>>,
     *     matched_fields: list<string>,
     *     pagination: array{page: int, per_page: int, total: int, last_page: int, has_previous: bool, has_next: bool}
     * }
     */
    public function searchByDate(string $input, int $page = 1): array
    {
        $date = $this->dates->parsePublicDate($input);

        if ($date === null) {
            return $this->emptySearch('INVALID_QUERY', 'date', null, null, $page);
        }

        $iso = $this->dates->toIsoDate($date);

        $query = $this->results->publiclyVisibleQuery()
            ->where('draw_date', '=', $iso);

        return $this->paginate($query, 'date', $iso, null, $page, ['draw_date']);
    }

    /**
     * Longest public search term accepted, exposed so the request validator
     * and this service cannot disagree.
     */
    public function maxTermLength(): int
    {
        $max = $this->config->get('national_lottery.page.max_search_length');

        return is_int($max) && $max > 0 ? min($max, 64) : 32;
    }

    /**
     * @param  Builder<NationalLotteryDraw>  $query
     * @param  list<string>  $matchedFields
     * @return array{
     *     status: string,
     *     query: array{type: string, term: string|null, field: string|null},
     *     matches: list<array<string, mixed>>,
     *     matched_fields: list<string>,
     *     pagination: array{page: int, per_page: int, total: int, last_page: int, has_previous: bool, has_next: bool}
     * }
     */
    private function paginate(
        Builder $query,
        string $type,
        ?string $term,
        ?string $field,
        int $page,
        array $matchedFields,
    ): array {
        $page = max(1, min($page, 10000));
        $perPage = $this->perPage();

        $paginator = $query
            ->with($this->results->publicRelations())
            ->orderByDesc('draw_date')
            ->orderByDesc('id')
            ->paginate(perPage: $perPage, page: $page);

        $matches = [];

        foreach ($paginator->items() as $draw) {
            if ($draw instanceof NationalLotteryDraw) {
                $matches[] = $this->results->projectDraw($draw);
            }
        }

        return [
            'status' => $matches === [] ? 'RESULT_NOT_FOUND' : 'RESULT_FOUND',
            'query' => [
                'type' => $type,
                'term' => $term,
                'field' => $field,
            ],
            'matches' => $matches,
            'matched_fields' => $matchedFields,
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
     *     query: array{type: string, term: string|null, field: string|null},
     *     matches: list<array<string, mixed>>,
     *     matched_fields: list<string>,
     *     pagination: array{page: int, per_page: int, total: int, last_page: int, has_previous: bool, has_next: bool}
     * }
     */
    private function emptySearch(string $status, string $type, ?string $term, ?string $field, int $page): array
    {
        $allowed = $this->config->get('national_lottery.public_lookup_states');
        $status = is_array($allowed) && in_array($status, $allowed, true) ? $status : 'UNAVAILABLE';

        return [
            'status' => $status,
            'query' => [
                'type' => $type,
                'term' => $term,
                'field' => $field,
            ],
            'matches' => [],
            'matched_fields' => [],
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

    private function perPage(): int
    {
        $default = $this->config->get('national_lottery.page.per_page');
        $default = is_int($default) && $default > 0 ? $default : 20;

        $max = $this->config->get('national_lottery.page.max_per_page');
        $max = is_int($max) && $max > 0 ? $max : 50;

        return min($default, $max);
    }
}
