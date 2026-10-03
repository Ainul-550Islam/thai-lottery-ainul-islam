<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\DTOs\Betting\BulkBetSelectionData;
use App\Http\Requests\Web\BetPurchaseRequest;
use App\Models\Draw;
use App\Services\Betting\BulkBetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Authenticated browser Bet Slip purchase endpoint.
 *
 * Delegates entirely to the canonical BulkBetService to guarantee identical
 * ledger writes, number-limit checks, balance locks, idempotency, draw-close
 * rules, and responsible gaming limits as the API.
 */
final class BetPurchaseController
{
    public function __construct(
        private readonly BulkBetService $bulkBets,
    ) {}

    public function store(BetPurchaseRequest $request): JsonResponse
    {
        $user = Auth::user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => (string) trans('player.auth_required_bet'),
            ], 401);
        }

        // BLOCKER CLOSURE — browser revenue path.
        //
        // Validation now lives in BetPurchaseRequest, which accepts the
        // `draw_id` the bet slip actually posts as well as the legacy
        // `draw_reference`, and resolves either to the canonical Draw. The
        // controller previously inline-validated `draw_reference` only, so
        // every browser purchase 422'd while the API returned 201.
        $validated = $request->validated();
        $draw = $request->resolveDraw();

        if (! $draw instanceof Draw) {
            return response()->json([
                'success' => false,
                'message' => (string) trans('player.draw_selection_invalid'),
            ], 422);
        }

        $drawId = (int) $draw->getKey();
        $clientKey = (string) $validated['client_key'];

        $selections = [];
        foreach ($validated['items'] as $item) {
            $selections[] = BulkBetSelectionData::fromRequestArray([
                'market' => (string) $item['market'],
                'number' => (string) $item['number'],
                'stake' => (string) $item['stake'],
            ]);
        }

        $report = $this->bulkBets->purchase((int) $user->id, $drawId, $selections, $clientKey);

        if ($report['refused'] > 0 && $report['purchased'] === 0 && $report['replayed'] === 0) {
            $firstRefusal = null;
            foreach ($report['items'] as $item) {
                if ($item['outcome'] === 'refused' && ! empty($item['reason'])) {
                    $firstRefusal = $item['reason'];
                    break;
                }
            }

            return response()->json([
                'success' => false,
                'message' => $firstRefusal ?? (string) trans('player.bet_slip_refused'),
                'report' => $report,
            ], 422);
        }

        $isFullReplay = $report['replayed'] > 0 && $report['purchased'] === 0;

        return response()->json([
            'success' => true,
            'message' => $isFullReplay
                ? (string) trans('player.bet_slip_replayed')
                : (string) trans('player.bet_slip_accepted'),
            // Envelope parity with the API surface ({success, data, meta}).
            // `report` is retained for the existing browser client and is
            // deprecated; read data.report instead.
            'data' => ['report' => $report],
            'meta' => ['replayed' => $isFullReplay, 'draw_id' => $drawId],
            'report' => $report,
        ], $isFullReplay ? 200 : 201);
    }
}
