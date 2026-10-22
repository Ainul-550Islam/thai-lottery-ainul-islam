<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Order\OrderPlacementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class OrderPlacementController extends Controller
{
    public function __construct(
        private readonly OrderPlacementService $orderPlacementService
    ) {}

    /**
     * Handle high-concurrency order/entry placement.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'draw_id' => ['required', 'uuid'],
            'ticket_number' => ['required', 'string', 'min:2', 'max:6'],
            'bet_type' => ['required', 'string', 'in:DIRECT_6D,FRONT_3D,BACK_3D,BACK_2D,RUNNING_NUMBER'],
            'stake_amount' => ['required', 'numeric', 'min:1.00', 'max:50000.00'],
            'agent_id' => ['nullable', 'uuid'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'VALIDATION_FAILED',
                'messages' => $validator->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $userId = (string) $request->user()?->id ?? (string) $request->input('user_id');
        $idempotencyKey = (string) $request->header('X-Idempotency-Key', $request->input('idempotency_key'));

        try {
            $entry = $this->orderPlacementService->placeOrder(
                userId: $userId,
                drawId: (string) $request->input('draw_id'),
                ticketNumber: (string) $request->input('ticket_number'),
                betType: (string) $request->input('bet_type'),
                stakeAmount: bcadd((string) $request->input('stake_amount'), '0', 4),
                agentId: $request->input('agent_id') ? (string) $request->input('agent_id') : null,
                idempotencyKey: $idempotencyKey ?: null
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $entry->id,
                    'voucher_number' => $entry->voucher_number,
                    'draw_id' => $entry->draw_id,
                    'ticket_number' => $entry->ticket_number,
                    'bet_type' => $entry->bet_type,
                    'stake_amount' => $entry->stake_amount,
                    'potential_win_amount' => $entry->potential_win_amount,
                    'status' => $entry->status,
                    'placed_at' => $entry->placed_at?->toIso8601String(),
                ],
            ], Response::HTTP_CREATED);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => 'ORDER_PLACEMENT_REJECTED',
                'message' => $e->getMessage(),
            ], Response::HTTP_CONFLICT);
        }
    }
}
