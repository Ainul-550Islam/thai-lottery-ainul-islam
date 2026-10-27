<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\GloDealerException;
use App\Services\Lottery\GloPublicResultService;
use App\Services\Lottery\PrizeCheckService;
use App\Services\PublicPages\ResultsPageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public results hub and the JSON read model used by the public results lane.
 *
 * This controller deliberately composes the existing public projections. It does
 * not contain draw numbers, prize amounts, provider claims, or fixture data.
 */
final class GloResultsPageController
{
    public function __construct(
        private readonly ResultsPageService $results,
        private readonly GloPublicResultService $publicResults,
        private readonly PrizeCheckService $prizes,
    ) {
    }

    public function index(Request $request): View
    {
        $data = $this->results->resultsData();

        return view('results.index', [
            'currentStatus' => $data['currentStatus'],
            'rows' => $data['rows'],
        ]);
    }

    public function latestDrawApi(): JsonResponse
    {
        $data = $this->results->resultsData();

        return response()->json([
            'status' => $data['rows'] === [] ? 'NO_PUBLIC_DATA' : 'success',
            'data' => array_map(static function (array $row): array {
                return [
                    'draw_number' => $row['draw_number'],
                    'draw_date' => $row['draw_date'],
                    'first_prize' => $row['first_prize'],
                    'second_prize' => $row['second_prize'],
                    'third_prize' => $row['third_prize'],
                    'consolation_prizes' => $row['consolation_prizes'],
                    'source_state' => $row['source_state'],
                ];
            }, $data['rows']),
        ]);
    }

    public function checkTicketApi(Request $request): JsonResponse
    {
        $number = trim((string) $request->input('number', ''));

        if (preg_match('/^\d{6}$/', $number) !== 1) {
            return response()->json([
                'status' => 'INVALID_INPUT',
                'message' => 'Enter exactly six digits.',
            ], 422);
        }

        try {
            $result = $this->publicResults->checkSixDigit($number);
        } catch (GloDealerException $e) {
            return response()->json([
                'status' => 'UNAVAILABLE',
                'message' => 'Ticket checking is temporarily unavailable.',
            ], 503);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'status' => 'UNAVAILABLE',
                'message' => 'Ticket checking is temporarily unavailable.',
            ], 503);
        }

        return response()->json([
            'status' => 'success',
            'data' => $result,
        ]);
    }

    /**
     * POST /api/v1/glo/prize-check
     *
     * THE PRIZE LANE, AS DISTINCT FROM THE TICKET LANE.
     *
     * `checkTicketApi` answers "what does the published draw say about these
     * digits" — a read of the result sheet. This answers a different question:
     * "does this ticket win, at which tier, for how much, and can it still be
     * claimed". The two are separate because the second one states an AMOUNT,
     * and an amount must come from the prize catalogue plus the stamp-duty
     * calculator rather than from anything the client sends.
     *
     * THE CLIENT NEVER SENDS AN AMOUNT, A TIER OR A DRAW ID. It sends six
     * digits and, optionally, a draw number. Everything money-shaped in the
     * response is computed server-side from config('glo.prizes') and the
     * published result row, so a tampered request cannot produce a larger
     * figure than the draw actually pays.
     *
     * STATES, NOT ERRORS, FOR EXPECTED ABSENCES. A draw with no published
     * result, a published fixture row in production, and an unconfigured lane
     * are all answers a player should read calmly, so they come back 200 with
     * `state` set and the script renders the matching notice. Only a malformed
     * ticket number is a 422, because only that is the visitor's mistake.
     */
    public function prizeCheckApi(Request $request): JsonResponse
    {
        $number = $this->prizes->normaliseTicket((string) $request->input('number', ''));

        if ($number === null) {
            return response()->json([
                'status' => 'INVALID_INPUT',
                'state' => PrizeCheckService::STATE_INVALID,
                'message' => (string) trans('frontend_ops.results.invalid_ticket'),
            ], 422);
        }

        $drawNumber = trim((string) $request->input('draw', ''));

        $payload = $drawNumber === ''
            ? $this->prizes->checkLatest($number)
            : $this->prizesForDraw($number, $drawNumber);

        return response()->json([
            'status' => 'success',
            'data' => $payload,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function prizesForDraw(string $number, string $drawNumber): array
    {
        $draw = \App\Models\Draw::query()
            ->where('draw_number', $drawNumber)
            ->first();

        if ($draw === null) {
            return [
                'state' => PrizeCheckService::STATE_NOT_CONFIGURED,
                'verdict' => null,
                'reason' => 'unknown_draw',
                'ticket' => $number,
                'draw' => ['draw_number' => $drawNumber, 'scheduled_at' => null, 'published_at' => null],
                'provenance' => ['state' => null, 'label_key' => null],
                'tier' => null,
                'claim_window' => null,
                'currency' => null,
            ];
        }

        return $this->prizes->check($number, $draw);
    }
}
