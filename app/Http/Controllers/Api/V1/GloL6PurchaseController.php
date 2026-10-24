<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\FinancialException;
use App\Exceptions\GloL6PurchaseException;
use App\Exceptions\ResponsibleGamingLimitException;
use App\Exceptions\SelfExclusionException;
use App\Exceptions\WalletReservationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Glo\PurchaseGloL6TicketRequest;
use App\Http\Requests\Glo\ReserveGloL6TicketRequest;
use App\Http\Resources\GloL6TicketPurchaseResource;
use App\Models\Draw;
use App\Models\GloL6TicketReservation;
use App\Models\GloTicket;
use App\Models\User;
use App\Services\Lottery\GloL6PurchaseCapabilityService;
use App\Services\Lottery\GloL6TransactionalPurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class GloL6PurchaseController extends Controller
{
    public function __construct(
        private readonly GloL6PurchaseCapabilityService $capabilityService,
        private readonly GloL6TransactionalPurchaseService $purchaseService,
    ) {}

    public function capability(Request $request): JsonResponse
    {
        $draw = null;
        $drawId = $request->query('draw_id');
        if (is_numeric($drawId) && (int) $drawId > 0) {
            $draw = Draw::query()->find((int) $drawId);
        }

        $series = $request->query('set_series');
        $resolvedSeries = is_numeric($series) && (int) $series > 0 ? (int) $series : null;

        return response()->json([
            'success' => true,
            'data' => $this->capabilityService->capability($draw, $resolvedSeries),
        ]);
    }

    public function reserve(ReserveGloL6TicketRequest $request): JsonResponse
    {
        /** @var User $buyer */
        $buyer = $request->user();
        Gate::forUser($buyer)->authorize('create', GloL6TicketReservation::class);

        try {
            $result = $this->purchaseService->reserveTicket(
                buyer: $buyer,
                drawId: (int) $request->validated('draw_id'),
                ticketNumber: (string) $request->validated('ticket_number'),
                series: $request->resolvedSeries(),
                idempotencyKey: (string) $request->validated('idempotency_key'),
                expectedUnitPrice: $request->validated('expected_unit_price'),
            );

            $status = $result['replayed'] ? 200 : 201;

            return response()->json([
                'success' => true,
                'data' => (new GloL6TicketPurchaseResource($result))->toArray($request),
            ], $status);
        } catch (GloL6PurchaseException $e) {
            return $this->domainErrorResponse($e);
        } catch (ResponsibleGamingLimitException|SelfExclusionException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'responsible_gaming_blocked',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        } catch (WalletReservationException|FinancialException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'insufficient_or_locked_funds',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }
    }

    public function confirm(PurchaseGloL6TicketRequest $request, string $reference): JsonResponse
    {
        /** @var User $buyer */
        $buyer = $request->user();

        /** @var GloL6TicketReservation|null $reservation */
        $reservation = GloL6TicketReservation::query()
            ->where('reservation_reference', $reference)
            ->first();

        if (! $reservation instanceof GloL6TicketReservation || ! Gate::forUser($buyer)->allows('confirm', $reservation)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'glo_l6_reservation_not_found',
                    'message' => 'GLO L6 ticket reservation was not found.',
                ],
            ], 404);
        }

        try {
            $result = $this->purchaseService->confirmReservation(
                buyer: $buyer,
                reservationReference: $reference,
                idempotencyKey: (string) $request->validated('idempotency_key'),
            );

            $status = $result['replayed'] ? 200 : 201;

            return response()->json([
                'success' => true,
                'data' => (new GloL6TicketPurchaseResource($result))->toArray($request),
            ], $status);
        } catch (GloL6PurchaseException $e) {
            return $this->domainErrorResponse($e);
        } catch (ResponsibleGamingLimitException|SelfExclusionException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'responsible_gaming_blocked',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        } catch (WalletReservationException|FinancialException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'wallet_settlement_failed',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }
    }

    public function purchase(ReserveGloL6TicketRequest $request): JsonResponse
    {
        /** @var User $buyer */
        $buyer = $request->user();
        Gate::forUser($buyer)->authorize('create', GloTicket::class);

        try {
            $result = $this->purchaseService->purchaseImmediate(
                buyer: $buyer,
                drawId: (int) $request->validated('draw_id'),
                ticketNumber: (string) $request->validated('ticket_number'),
                series: $request->resolvedSeries(),
                idempotencyKey: (string) $request->validated('idempotency_key'),
                expectedUnitPrice: $request->validated('expected_unit_price'),
            );

            $status = $result['replayed'] ? 200 : 201;

            return response()->json([
                'success' => true,
                'data' => (new GloL6TicketPurchaseResource($result))->toArray($request),
            ], $status);
        } catch (GloL6PurchaseException $e) {
            return $this->domainErrorResponse($e);
        } catch (ResponsibleGamingLimitException|SelfExclusionException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'responsible_gaming_blocked',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        } catch (WalletReservationException|FinancialException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'insufficient_or_locked_funds',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }
    }

    public function release(Request $request, string $reference): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        /** @var GloL6TicketReservation|null $reservation */
        $reservation = GloL6TicketReservation::query()
            ->where('reservation_reference', $reference)
            ->first();

        if (! $reservation instanceof GloL6TicketReservation || ! Gate::forUser($actor)->allows('release', $reservation)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'glo_l6_reservation_not_found',
                    'message' => 'GLO L6 ticket reservation was not found.',
                ],
            ], 404);
        }

        try {
            $released = $this->purchaseService->releaseReservation($actor, $reference, 'Released via API v1.');

            return response()->json([
                'success' => true,
                'data' => (new GloL6TicketPurchaseResource($released))->toArray($request),
            ]);
        } catch (GloL6PurchaseException $e) {
            return $this->domainErrorResponse($e);
        }
    }

    public function indexTickets(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $perPage = min(50, max(1, (int) $request->query('per_page', 15)));

        $paginator = GloTicket::query()
            ->where('owner_user_id', (int) $user->getKey())
            ->where('product', 'l6')
            ->orderByDesc('id')
            ->paginate($perPage);

        $items = array_map(
            fn (GloTicket $ticket): array => (new GloL6TicketPurchaseResource($ticket))->toArray($request),
            $paginator->items(),
        );

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function showTicket(Request $request, string $ticketReference): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var GloTicket|null $ticket */
        $ticket = GloTicket::query()
            ->where('ticket_reference', $ticketReference)
            ->where('product', 'l6')
            ->first();

        if (! $ticket instanceof GloTicket || ! Gate::forUser($user)->allows('view', $ticket)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'glo_l6_ticket_not_found',
                    'message' => 'GLO L6 ticket was not found.',
                ],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => (new GloL6TicketPurchaseResource($ticket))->toArray($request),
        ]);
    }

    private function domainErrorResponse(GloL6PurchaseException $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => $e->errorCode(),
                'message' => $e->getMessage(),
                'details' => $e->context(),
            ],
        ], $e->httpStatus());
    }
}
