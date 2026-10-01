<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\GloDealerException;
use App\Services\Lottery\GloPublicResultService;
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
}
