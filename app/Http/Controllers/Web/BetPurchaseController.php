<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\DTOs\Betting\BulkBetSelectionData;
use App\Models\Draw;
use App\Services\Betting\BulkBetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => (string) trans('player.auth_required_bet'),
            ], 401);
        }

        $validated = $request->validate([
            'draw_reference' => ['required', 'string', 'max:128'],
            'client_key' => ['required', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.market' => ['required', 'string', 'max:32'],
            'items.*.number' => ['required', 'string', 'regex:/^\d{1,6}$/'],
            'items.*.stake' => ['required', 'string', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/'],
        ]);

        $draw = Draw::query()
            ->where('draw_number', (string) $validated['draw_reference'])
            ->first();

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
            'report' => $report,
        ], $isFullReplay ? 200 : 201);
    }
}
