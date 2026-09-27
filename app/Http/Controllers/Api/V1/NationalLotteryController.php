<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Responses\ApiResponse;
use App\Services\Lottery\NationalLotteryDateService;
use App\Services\Lottery\NationalLotteryHistoryService;
use App\Services\Lottery\NationalLotteryResultService;
use App\Services\Lottery\NationalLotterySearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public JSON surface for the National Lottery lane (PROMPT 5).
 *
 * THE SAME PROJECTION THE HTML PAGES RENDER
 * ---------------------------------------------------------------------------
 * Every endpoint below returns the array produced by the same service method
 * the Blade page uses. That is deliberate: a second, hand-built JSON shape is
 * a second place for a leading zero to be lost, for an unpublished draw to
 * slip out, or for a provenance badge to disagree with the page. There is one
 * projection, and both surfaces render it.
 *
 * ANONYMOUS AND BOUNDED
 * ---------------------------------------------------------------------------
 * No authentication, because the data is public. Every parameter is validated
 * against a bound before a query exists: the year is regex-checked and
 * range-checked, the page is capped, the number is shape-checked, the search
 * term is length-capped. /search carries the dedicated
 * 'national-result-search' limiter rather than the generic 'api' one, because
 * the six-digit space is enumerable and the generic limiter is sized for
 * ordinary API traffic.
 *
 * EVERY RESPONSE IS A SUCCESS ENVELOPE WITH A STATUS INSIDE
 * ---------------------------------------------------------------------------
 * "No published result for this year" is not an HTTP error - the request was
 * well formed and the answer is knowable. Those cases return 200 with
 * status NO_PUBLIC_DATA or RESULT_NOT_FOUND, so a client never has to parse
 * an error body to learn an ordinary fact. A malformed request returns 422
 * through the framework validator. Nothing here ever emits a driver message,
 * an SQLSTATE or a stack trace.
 */
final class NationalLotteryController
{
    public function __construct(
        private readonly NationalLotteryResultService $results,
        private readonly NationalLotteryHistoryService $history,
        private readonly NationalLotterySearchService $search,
        private readonly NationalLotteryDateService $dates,
    ) {}

    /**
     * GET /api/v1/national-lottery/results
     *
     * The current published result, plus the recent strip and the real year
     * index.
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
        ], 'National Lottery results.');
    }

    /**
     * GET /api/v1/national-lottery/results/{draw}
     */
    public function show(Request $request, string $draw): JsonResponse
    {
        unset($request);

        $projection = $this->results->resultForReference($draw);

        return ApiResponse::success([
            'status' => $projection['status'],
            'result' => $projection,
        ], 'National Lottery draw.');
    }

    /**
     * GET /api/v1/national-lottery/results/year/{year}
     */
    public function year(Request $request, string $year): JsonResponse
    {
        if (preg_match('/^[0-9]{1,4}$/', $year) !== 1) {
            return ApiResponse::success([
                'status' => 'INVALID_QUERY',
                'history' => null,
                'years' => $this->history->availableYears(),
            ], 'National Lottery history.');
        }

        $gregorian = $this->dates->normaliseYearInput((int) $year);

        if ($gregorian === null) {
            return ApiResponse::success([
                'status' => 'NO_PUBLIC_DATA',
                'history' => null,
                'years' => $this->history->availableYears(),
            ], 'National Lottery history.');
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
        ], 'National Lottery history.');
    }

    /**
     * GET /api/v1/national-lottery/search?number=|date=
     *
     * Exactly one of number or date. Supplying both is a malformed request,
     * not something to silently resolve by preferring one.
     */
    public function search(Request $request): JsonResponse
    {
        $maxLength = $this->search->maxTermLength();

        $validated = $request->validate([
            'number' => ['nullable', 'string', 'max:'.$maxLength],
            'date' => ['nullable', 'string', 'max:'.$maxLength],
            'field' => ['nullable', 'string', 'in:'.implode(',', NationalLotterySearchService::searchableFields())],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);

        $number = isset($validated['number']) ? trim((string) $validated['number']) : '';
        $date = isset($validated['date']) ? trim((string) $validated['date']) : '';
        $field = isset($validated['field']) && $validated['field'] !== '' ? (string) $validated['field'] : null;
        $page = isset($validated['page']) ? (int) $validated['page'] : 1;

        if ($number !== '' && $date !== '') {
            return ApiResponse::error(
                'invalid_query',
                'Provide either a number or a date, not both.',
                422,
            );
        }

        if ($number === '' && $date === '') {
            return ApiResponse::error(
                'invalid_query',
                'Provide a number or a date to search.',
                422,
            );
        }

        $outcome = $number !== ''
            ? $this->search->searchByNumber($number, $field, $page)
            : $this->search->searchByDate($date, $page);

        return ApiResponse::success([
            'status' => $outcome['status'],
            'search' => $outcome,
        ], 'National Lottery search.');
    }

    private function maxPerPage(): int
    {
        $max = config('national_lottery.page.max_per_page');

        return is_int($max) && $max > 0 ? $max : 50;
    }
}
