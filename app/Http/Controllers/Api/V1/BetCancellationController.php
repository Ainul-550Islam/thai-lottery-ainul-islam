<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTOs\Betting\BetCancellationData;
use App\Exceptions\BetDomainException;
use App\Exceptions\FinancialException;
use App\Exceptions\RiskException;
use App\Http\Requests\Api\V1\CancelBetRequest;
use App\Http\Resources\BetCancellationResource;
use App\Http\Responses\ApiResponse;
use App\Services\Betting\BetCancellationService;
use Illuminate\Http\JsonResponse;

/**
 * Player-facing bet cancellation endpoint.
 *
 * Transport-only: the request object has already validated and normalised the
 * payload; this controller passes it through to the service and shapes the
 * envelope. Every gate (ownership, window, draw state, status, money) lives in
 * the service; refusals arrive as BetDomainException and are answered with
 * 422 plus the domain code, never an ad-hoc vocabulary.
 */
class BetCancellationController
{
    public function __construct(private readonly BetCancellationService $cancellations)
    {
    }

    /**
     * POST /api/v1/bets/{bet}/cancel
     */
    public function store(CancelBetRequest $request, string $bet): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        try {
            $result = $this->cancellations->cancel(BetCancellationData::fromRequestArray(
                (int) $user->getAuthIdentifier(),
                $bet,
                $request->validated(),
            ));
        } catch (BetDomainException|RiskException $e) {
            // A domain refusal and a risk refusal are the same thing to the caller: the
            // cancellation did not happen, for a named reason, and nothing moved. They
            // are answered with the domain code and 422 — never a 500, which would say
            // the server broke rather than the request was refused, and never a 403,
            // which would say the caller lacks permission.
            return ApiResponse::error($e->errorCode() ?? 'bet_cancellation_refused', $e->getMessage(), 422);
        } catch (FinancialException $e) {
            // A wallet/ledger refusal is a state the player must not be able to probe:
            // "wallet 1 is Frozen" tells an attacker which wallet exists and in what
            // state. The code is kept for support, the detail is not.
            return ApiResponse::error(
                $e->errorCode() ?? 'bet_cancellation_financial_refusal',
                'The cancellation could not be completed safely; preserve the current purchase for review.',
                422,
            );
        }

        return ApiResponse::success(
            (new BetCancellationResource($result))->toArray($request),
            $result->isReplay() ? 'The bet was already cancelled.' : 'Bet cancelled and stake refunded.',
        );
    }
}
