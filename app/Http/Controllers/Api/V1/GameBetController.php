<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTOs\LotteryGames\DigitBetSelection;
use App\Exceptions\LotteryGameException;
use App\Http\Controllers\Api\V1\Concerns\RendersGameResponses;
use App\Http\Requests\Api\V1\PlaceGameBetRequest;
use App\Http\Requests\Api\V1\QuoteGameBetRequest;
use App\Http\Resources\GameBetQuoteResource;
use App\Services\LotteryGames\GameBetPlacementService;
use Illuminate\Http\JsonResponse;

/**
 * The write side of the digit-game API: price a bet, and buy it.
 *
 * THE COMPLETE LIST OF WHAT THIS CONTROLLER DOES
 *   1. Reads the already structurally validated request.
 *   2. Reads the player id from the AUTHENTICATED request — never from the payload.
 *   3. Builds the digit bet DTO.
 *   4. Calls GameBetPlacementService (quote or place).
 *   5. Maps the result or the refusal to a response.
 *
 * WHAT IT DOES NOT DO, AND WHY EACH OMISSION MATTERS
 *   - No digit validation. DigitNumberValidator owns it, and doing it here would let a
 *     request pass this class and fail later, after a lock was taken.
 *   - No capability check. GameAvailabilityService owns the nine gates and
 *     GameBetPlacementService calls it as step 2, inside the same request.
 *   - No liability read or reservation. LiabilityExposureService is the sole writer of
 *     those counters; a controller that peeked at a cap would be reading a value that is
 *     stale the moment another placement commits.
 *   - No wallet read, no balance check, no ledger write. The stake is spent by the
 *     existing Batch-1 purchase path, inside its own transaction and its own locks.
 *   - No payout arithmetic. PayoutCalculator owns it, and the quote and the placement use
 *     the same call so they cannot disagree by a cent.
 *   - No transaction of its own. The purchase lane owns its transaction, and an outer one
 *     here would hold a liability lock across a wallet commit.
 *
 * IDENTITY IS TAKEN FROM THE SESSION ONLY
 * `$request->user()->getKey()` is the only source of the player id. The FormRequests have
 * no `user_id` field at all, so a crafted body cannot place a bet on another account.
 */
final class GameBetController
{
    use RendersGameResponses;

    public function __construct(private readonly GameBetPlacementService $placement) {}

    /**
     * POST /api/v1/games/{code}/bets/quote
     *
     * Prices a selection. Never reserves, never spends, never consumes a voucher: a quote
     * that had a side effect would be a purchase nobody asked for.
     */
    public function quote(QuoteGameBetRequest $request, string $code): JsonResponse
    {
        // The request owns the shape question (flat fields or a one-element selections list,
        // voucher_id or free_bet_voucher_id); the controller reads one canonical answer.
        $selection = $request->selectionPayload();
        $funding = $request->input('funding_source');

        try {
            $quote = $this->placement->quote(
                gameCode: $code,
                betType: $selection['bet_type'],
                number: $selection['number'],
                stake: $selection['stake'],
                fundingSource: is_string($funding) ? $funding : null,
                voucherId: $request->voucherId(),
                userId: $this->userId($request),
            );
        } catch (LotteryGameException $e) {
            return $this->gameError($e);
        }

        return $this->gameSuccess(
            (new GameBetQuoteResource($quote))->toArray($request),
            $quote['sellable'] ? 'Quote prepared.' : 'Quote unavailable.',
            200,
            ['expires_with_the_draw' => true],
        );
    }

    /**
     * POST /api/v1/games/{code}/bets
     *
     * Places the bet. 201 with the receipt; every refusal is mapped by
     * RendersGameResponses, so a 409 means "state", a 422 means "this body" and a 503
     * means "this deployment".
     */
    public function store(PlaceGameBetRequest $request, string $code): JsonResponse
    {
        $payload = $request->selectionPayload();

        $selection = new DigitBetSelection(
            gameCode: $code,
            betType: $payload['bet_type'],
            number: $payload['number'],
            stake: $payload['stake'],
        );

        try {
            $receipt = $this->placement->place(
                userId: $this->userId($request),
                selection: $selection,
                drawId: $request->drawId(),
                idempotencyKey: (string) $request->input('idempotency_key'),
                voucherId: $request->voucherId(),
                fundingSource: $request->fundingSource(),
            );
        } catch (LotteryGameException $e) {
            return $this->gameError($e);
        }

        return $this->gameSuccess($receipt, 'Bet placed.', 201, [
            'draw_id' => $receipt['draw_id'],
            'game_code' => $receipt['game_code'],
        ]);
    }

    /**
     * The authenticated player's id.
     *
     * The route group guarantees an authenticated user; the fallback of 0 exists only so
     * this method has a total signature, and a 0 reaches the placement service which
     * refuses it through the purchase lane rather than guessing an identity.
     */
    private function userId(QuoteGameBetRequest|PlaceGameBetRequest $request): int
    {
        $user = $request->user();

        return $user === null ? 0 : (int) $user->getKey();
    }
}
