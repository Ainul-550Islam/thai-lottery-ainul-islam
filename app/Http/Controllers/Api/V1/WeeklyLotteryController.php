<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Responses\ApiResponse;
use App\Services\Lottery\WeeklyLotteryDateService;
use App\Services\Lottery\WeeklyLotteryHistoryService;
use App\Services\Lottery\WeeklyLotteryResultService;
use App\Services\Lottery\WeeklyLotterySearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public JSON surface for the Weekly Lottery lane (PROMPT 6).
 *
 * THE SAME PROJECTION THE HTML PAGES RENDER
 * ---------------------------------------------------------------------------
 * Every endpoint returns the array produced by the same service method the
 * Blade page uses. A second, hand-built JSON shape would be a second place for
 * a leading zero to be lost, for an unpublished draw to slip out, or for a
 * provenance badge to disagree with the page. One projection, two surfaces.
 *
 * ANONYMOUS AND BOUNDED
 * ---------------------------------------------------------------------------
 * No authentication, because the data is public. Every parameter is validated
 * against a bound before a query exists: the year is regex- and range-checked,
 * the page is capped, the term is length-capped, and the search type must be
 * one of the closed list. /search carries the dedicated
 * 'weekly-result-search' limiter rather than the generic 'api' one, because
 * the six-digit space is enumerable and the generic limiter is sized for
 * ordinary API traffic.
 *
 * NO INTERNAL IDS, NO PRIVATE FIELDS. The projection is whitelisted field by
 * field in the service layer; nothing here adds to it.
 *
 * EVERY ORDINARY ANSWER IS A 200 WITH A STATUS INSIDE
 * ---------------------------------------------------------------------------
 * "No published result for this year" is not an HTTP error - the request was
 * well formed and the answer is knowable. Those cases return 200 with status
 * NO_PUBLIC_DATA, RESULT_UNAVAILABLE or RESULT_NOT_FOUND, so a client never
 * has to parse an error body to learn an ordinary fact. A malformed request
 * returns 422. Nothing here ever emits a driver message, an SQLSTATE or a
 * stack trace.
 */
final class WeeklyLotteryController
{
    public function __construct(
        private readonly WeeklyLotteryResultService $results,
        private readonly WeeklyLotteryHistoryService $history,
        private readonly WeeklyLotterySearchService $search,
        private readonly WeeklyLotteryDateService $dates,
    ) {}

    /**
     * GET /api/v1/weekly-lottery/results
     */
    public function index(Request $request): JsonResponse
    {
        unset($request);

        $current = $this->results->currentResult();

        $reference = is_array($current['draw'] ?? null) && isset($current['draw']['reference'])
            ? (string) $current['draw']['reference']
            : null;

        return ApiResponse::success([
            'status' => $current['status'],
            'current' => $current,
            'recent' => $this->history->recentDraws($reference),
            'years' => $this->history->availableYears(),
        ], 'Weekly Lottery results.');
    }

    /**
     * GET /api/v1/weekly-lottery/results/{draw}
     */
    public function show(Request $request, string $draw): JsonResponse
    {
        unset($request);

        $projection = $this->results->resultForReference($draw);

        return ApiResponse::success([
            'status' => $projection['status'],
            'result' => $projection,
        ], 'Weekly Lottery draw.');
    }

    /**
     * GET /api/v1/weekly-lottery/results/year/{year}
     */
    public function year(Request $request, string $year): JsonResponse
    {
        if (preg_match('/^[0-9]{1,4}$/', $year) !== 1) {
            return ApiResponse::success([
                'status' => 'INVALID_QUERY',
                'history' => null,
                'years' => $this->history->availableYears(),
            ], 'Weekly Lottery history.');
        }

        $gregorian = $this->dates->normaliseYearInput((int) $year);

        if ($gregorian === null) {
            return ApiResponse::success([
                'status' => 'NO_PUBLIC_DATA',
                'history' => null,
                'years' => $this->history->availableYears(),
            ], 'Weekly Lottery history.');
        }

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.$this->maxPerPage()],
        ]);

        $history = $this->history->historyForYear(
            $gregorian,
            isset($validated['page']) ? (int) $validated['page'] : 1,
            isset($validated['per_page']) ? (int) $validated['per_page'] : null,
        );

        return ApiResponse::success([
            'status' => $history['status'],
            'history' => $history,
            'years' => $this->history->availableYears(),
        ], 'Weekly Lottery history.');
    }

    /**
     * GET /api/v1/weekly-lottery/search?type=&term=
     *
     * The type is REQUIRED. It is never inferred from the term's length: with
     * only three fields, guessing would make the API assert a match in a field
     * the caller never asked about.
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', $this->search->searchTypes())],
            'term' => ['required', 'string', 'max:'.$this->search->maxTermLength()],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);

        $outcome = $this->search->search(
            (string) $validated['type'],
            trim((string) $validated['term']),
            isset($validated['page']) ? (int) $validated['page'] : 1,
        );

        return ApiResponse::success([
            'status' => $outcome['status'],
            'search' => $outcome,
        ], 'Weekly Lottery search.');
    }

    private function maxPerPage(): int
    {
        $max = config('weekly_lottery.page.max_per_page');

        return is_int($max) && $max > 0 ? $max : 50;
    }
}
