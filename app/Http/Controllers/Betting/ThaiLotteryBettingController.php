<?php

declare(strict_types=1);

namespace App\Http\Controllers\Betting;

use App\DTOs\Betting\BulkBetSelectionData;
use App\Enums\Currency;
use App\Enums\WalletType;
use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Services\Betting\BulkBetService;
use App\Services\Finance\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Compatibility adapter for the retired generic betting terminal.
 *
 * The old terminal contained fixture selections, floating-point money,
 * fabricated references and a presentation-only placement response. It is no
 * longer a source of betting or financial state. Browser traffic is redirected
 * to the authenticated canonical player bet slip, while the compatibility API
 * either reads the authenticated wallet or delegates a fully shaped request to
 * BulkBetService.
 */
final class ThaiLotteryBettingController extends Controller
{
    public function __construct(private readonly ?BulkBetService $bulkBets = null)
    {
    }

    public function index(Request $request): View|RedirectResponse
    {
        if ($request->user() !== null) {
            return redirect()->route('player.bet');
        }

        return redirect()->route('login');
    }

    public function getGameTypesApi(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'NOT_CONFIGURED',
            'message' => 'The legacy generic game-type endpoint is retired. Use the authenticated canonical betting contract.',
        ], 503);
    }

    public function getUserBalanceApi(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'status' => 'AUTHENTICATION_REQUIRED',
                'message' => 'Authentication is required to read a wallet balance.',
            ], 401);
        }

        $wallet = Wallet::query()
            ->where('user_id', $user->id)
            ->where('type', WalletType::Primary->value)
            ->where('currency', Currency::THB->value)
            ->first();

        if (! $wallet instanceof Wallet) {
            return response()->json([
                'status' => 'NOT_CONFIGURED',
                'message' => 'No THB wallet is configured for this account.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'user' => [
                'name' => (string) $user->name,
                'balance' => Money::of((string) $wallet->balance, Currency::THB)->toString(),
                'currency' => Currency::THB->value,
            ],
        ]);
    }

    public function placeWagersApi(Request $request): JsonResponse
    {
        if ($request->user() === null) {
            return response()->json([
                'status' => 'AUTHENTICATION_REQUIRED',
                'message' => 'Authentication is required to place wagers.',
            ], 401);
        }

        if ($this->bulkBets === null) {
            return response()->json([
                'status' => 'NOT_CONFIGURED',
                'message' => 'The canonical bulk betting service is not available.',
            ], 503);
        }

        $validated = $request->validate([
            'draw_id' => ['required', 'integer', 'min:1'],
            'client_key' => ['required', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.market' => ['required', 'string', 'max:32'],
            'items.*.number' => ['required', 'string', 'regex:/^\d{1,6}$/'],
            'items.*.stake' => ['required', 'numeric', 'min:0.01'],
        ]);

        $selections = array_map(
            static fn (array $item): BulkBetSelectionData => BulkBetSelectionData::fromRequestArray([
                'market' => (string) $item['market'],
                'number' => (string) $item['number'],
                'stake' => (string) $item['stake'],
            ]),
            $validated['items'],
        );

        try {
            $report = $this->bulkBets->purchase(
                (int) $request->user()->id,
                (int) $validated['draw_id'],
                $selections,
                (string) $validated['client_key'],
            );
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'status' => 'REFUSED',
                'message' => 'The canonical betting service refused or could not complete this request.',
            ], 422);
        }

        if (($report['purchased'] ?? 0) === 0 && ($report['replayed'] ?? 0) === 0) {
            return response()->json([
                'status' => 'REFUSED',
                'message' => 'The canonical betting service did not accept any selection.',
                'report' => $report,
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'The canonical betting service returned the wager report.',
            'report' => $report,
        ], 201);
    }
}
