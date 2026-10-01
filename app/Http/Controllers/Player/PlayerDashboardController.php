<?php

declare(strict_types=1);

namespace App\Http\Controllers\Player;

use App\Enums\Currency;
use App\Enums\DrawStatus;
use App\Http\Controllers\Controller;
use App\Models\Bet;
use App\Models\Draw;
use App\Models\Wallet;
use App\Services\Finance\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Compatibility adapter for the retired standalone dashboard controller.
 *
 * The canonical browser dashboard is PlayerWebController. The compatibility
 * read endpoints query the authenticated player's own rows and never return
 * fixture draw numbers, balances, wagers or payout amounts.
 */
final class PlayerDashboardController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        return $request->user() !== null
            ? redirect()->route('player.dashboard')
            : redirect()->route('login');
    }

    public function getSummaryApi(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return $this->authenticationRequired();
        }

        $wallet = Wallet::query()
            ->where('user_id', $user->id)
            ->where('currency', Currency::THB->value)
            ->first();
        $draw = Draw::query()->where('status', DrawStatus::Open->value)->orderBy('scheduled_at')->first();

        return response()->json([
            'status' => 'success',
            'draw' => $draw instanceof Draw ? [
                'number' => (string) $draw->draw_number,
                'status' => $draw->status->value,
                'scheduled_at' => $draw->scheduled_at?->toIso8601String(),
                'betting_close_at' => $draw->betting_close_at?->toIso8601String(),
            ] : null,
            'wallet' => $wallet instanceof Wallet ? [
                'balance' => Money::of((string) $wallet->balance, Currency::THB)->toString(),
                'currency' => Currency::THB->value,
            ] : null,
        ]);
    }

    public function getRecentWagersApi(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return $this->authenticationRequired();
        }

        $bets = Bet::query()
            ->where('user_id', $user->id)
            ->with(['draw', 'ticket'])
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(static fn (Bet $bet): array => [
                'bet_number' => (string) $bet->bet_number,
                'ticket_number' => $bet->ticket?->ticket_number,
                'draw_number' => $bet->draw?->draw_number,
                'stake' => (string) $bet->stake_amount,
                'status' => $bet->status->value,
                'created_at' => $bet->created_at?->toIso8601String(),
            ])->values()->all();

        return response()->json([
            'status' => 'success',
            'wagers' => $bets,
        ]);
    }

    public function cancelWagerApi(Request $request, string $ticketId): JsonResponse
    {
        return response()->json([
            'status' => 'NOT_CONFIGURED',
            'message' => (string) trans('player.not_configured_cancel_contract'),
        ], 503);
    }

    private function authenticationRequired(): JsonResponse
    {
        return response()->json([
            'status' => 'AUTHENTICATION_REQUIRED',
            'message' => (string) trans('player.auth_required_dashboard'),
        ], 401);
    }
}
