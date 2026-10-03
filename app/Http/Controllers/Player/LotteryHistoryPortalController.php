<?php

declare(strict_types=1);

namespace App\Http\Controllers\Player;

use App\DTOs\Betting\BetCancellationData;
use App\Enums\BetCancellationReason;
use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Models\Bet;
use App\Services\Betting\BetCancellationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Compatibility adapter for the retired member history portal.
 *
 * Browser and API reads are projections of the authenticated player's canonical
 * Bet, Draw, Ticket and BetItem records. This adapter contains no fixture slips,
 * balance totals, payout values, or synthetic identifiers. Re-bet remains
 * fail-closed until a verified amendment/rebet contract is exposed; cancellation
 * delegates to the existing audited BetCancellationService.
 */
final class LotteryHistoryPortalController
{
    public function __construct(
        private readonly BetCancellationService $cancellations,
    ) {}

    public function index(Request $request): RedirectResponse
    {
        return $request->user() !== null
            ? redirect()->route('player.bets')
            : redirect()->route('login');
    }

    public function getHistoryApi(Request $request): JsonResponse
    {
        $user = Auth::user();

        if ($user === null) {
            return $this->authenticationRequired();
        }

        $query = $this->ownedBets($user->id)->with(['draw', 'items', 'ticket']);
        $status = (string) $request->query('status', 'all');
        $market = (string) $request->query('market', 'all');
        $drawDate = $request->query('draw_date');
        $search = trim((string) $request->query('q', ''));

        if ($status !== 'all' && in_array($status, array_column(BetStatus::cases(), 'value'), true)) {
            $query->where('status', $status);
        }

        if ($market !== 'all' && $market !== '') {
            $marketType = BetType::tryFrom($market);

            if ($marketType instanceof BetType) {
                $query->where('type', $marketType);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if (is_string($drawDate) && $drawDate !== '') {
            $query->whereHas('draw', static function ($draws) use ($drawDate): void {
                $draws->whereDate('scheduled_at', $drawDate);
            });
        }

        if ($search !== '') {
            $query->where(static function ($searchQuery) use ($search): void {
                $searchQuery
                    ->where('bet_number', 'like', '%'.$search.'%')
                    ->orWhereHas('ticket', static function ($tickets) use ($search): void {
                        $tickets->where('ticket_number', 'like', '%'.$search.'%');
                    })
                    ->orWhereHas('items', static function ($items) use ($search): void {
                        $items->where('number', 'like', '%'.$search.'%');
                    });
            });
        }

        $bets = $query->latest('id')->paginate(15);
        $rows = $bets->getCollection()
            ->map(fn (Bet $bet): array => $this->publicBet($bet))
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data' => $rows,
            'meta' => [
                'current_page' => $bets->currentPage(),
                'last_page' => $bets->lastPage(),
                'per_page' => $bets->perPage(),
                'total' => $bets->total(),
            ],
        ]);
    }

    public function getSlipDetailApi(Request $request, string $slipId): JsonResponse
    {
        $user = Auth::user();

        if ($user === null) {
            return $this->authenticationRequired();
        }

        $bet = $this->ownedBets($user->id)
            ->with(['draw', 'items', 'ticket'])
            ->where(static function ($query) use ($slipId): void {
                $query->where('bet_number', $slipId)->orWhere('uuid', $slipId);
            })
            ->first();

        if (! $bet instanceof Bet) {
            return response()->json([
                'success' => false,
                'status' => 'NOT_FOUND',
                'message' => (string) trans('player.bet_not_found'),
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->publicBet($bet),
        ]);
    }

    public function rebetSlipApi(Request $request, string $slipId): JsonResponse
    {
        if ($request->user() === null) {
            return $this->authenticationRequired();
        }

        return response()->json([
            'success' => false,
            'status' => 'NOT_CONFIGURED',
            'message' => (string) trans('player.not_configured_rebet'),
        ], 503);
    }

    public function cancelSlipApi(Request $request, string $slipId): JsonResponse
    {
        $user = Auth::user();

        if ($user === null) {
            return $this->authenticationRequired();
        }

        $validated = $request->validate([
            'reason' => ['sometimes', 'string', Rule::in(BetCancellationReason::values())],
            'client_key' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ]);

        try {
            $result = $this->cancellations->cancel(BetCancellationData::fromRequestArray(
                (int) $user->id,
                $slipId,
                [
                    'reason' => $validated['reason'] ?? BetCancellationReason::PlayerRequest->value,
                    'client_key' => $validated['client_key'] ?? null,
                ],
            ));
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'status' => 'CANCELLATION_REFUSED',
                'message' => (string) trans('player.not_configured_cancel_refused'),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'status' => 'CANCELLED',
            'data' => [
                'bet_number' => (string) $result->bet->bet_number,
                'status' => $result->bet->status->value,
                'refund_amount' => (string) $result->refundAmount,
                'currency' => (string) $result->currency,
                'replayed' => $result->replayed,
            ],
        ]);
    }

    /**
     * @return Builder<Bet>
     */
    private function ownedBets(int $userId): Builder
    {
        return Bet::query()->where('user_id', $userId);
    }

    /**
     * @return array<string, mixed>
     */
    private function publicBet(Bet $bet): array
    {
        return [
            'bet_number' => (string) $bet->bet_number,
            'ticket_number' => $bet->ticket?->ticket_number,
            'draw_number' => $bet->draw?->draw_number,
            'draw_date' => $bet->draw?->scheduled_at?->toDateString(),
            'status' => $bet->status->value,
            'stake_amount' => (string) $bet->stake_amount,
            'potential_payout' => (string) $bet->potential_payout,
            'actual_payout' => (string) $bet->actual_payout,
            'currency' => $bet->currency->value,
            'placed_at' => $bet->placed_at?->toIso8601String(),
            'items' => $bet->items->map(static fn ($item): array => [
                'market' => $bet->type->value,
                'number' => (string) $item->number,
                'stake' => (string) $item->amount,
                'potential_payout' => (string) $item->potential_payout,
            ])->values()->all(),
        ];
    }

    private function authenticationRequired(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'status' => 'AUTHENTICATION_REQUIRED',
            'message' => (string) trans('player.auth_required_history'),
        ], 401);
    }
}
