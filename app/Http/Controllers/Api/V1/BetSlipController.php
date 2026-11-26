<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\AddBetSlipItemRequest;
use App\Http\Requests\Api\V1\ConfirmBetSlipRequest;
use App\Http\Resources\BetSlipResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\BetSlip\BetSlipConfirmationService;
use App\Services\BetSlip\BetSlipPricingService;
use App\Services\BetSlip\BetSlipRefused;
use App\Services\BetSlip\BetSlipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The member's draft slip: read, add, remove, clear, confirm.
 *
 * THIN, ON PURPOSE
 * No arithmetic, no validation rules of its own, no pricing. Every one of those
 * lives in a service that a test can call without an HTTP request, and the
 * controller's only jobs are: resolve the authenticated member (never a body
 * field), call the service, and shape the answer through ApiResponse.
 *
 * The member id comes from $request->user() in every method. There is no route
 * parameter that names a member and no body field that could, so this surface
 * cannot act on somebody else's slip even if a client sends a member_id.
 */
final class BetSlipController
{
    public function __construct(
        private readonly BetSlipService $slips,
        private readonly BetSlipPricingService $pricing,
        private readonly BetSlipConfirmationService $confirmation,
    ) {
    }

    /**
     * GET /me/bet-slip
     *
     * Returns the current draft WITHOUT creating one: a GET that inserts a row is
     * a lie in the HTTP contract and a cache-poisoning hazard.
     */
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $slip = $this->slips->currentDraft((int) $user->id);

        if ($slip === null) {
            return ApiResponse::success(
                [
                    'slip' => null,
                    'pricing' => [
                        'currency' => 'THB',
                        'items' => [],
                        'total_stake' => '0.00',
                        'total_potential_win' => '0.00',
                        'provisional' => true,
                        'priceable' => true,
                    ],
                    'max_items' => $this->slips->maxItems(),
                ],
                'No draft slip.',
            );
        }

        return ApiResponse::success([
            'slip' => $this->slipPayload($slip->fresh() ?? $slip),
            'pricing' => $this->pricing->price($slip),
            'max_items' => $this->slips->maxItems(),
        ], 'Draft slip loaded.');
    }

    /**
     * POST /me/bet-slip/items
     */
    public function addItem(AddBetSlipItemRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /*
         * SHAPE IS THE FORM REQUEST'S JOB; LAW IS THE SERVICE'S.
         * AddBetSlipItemRequest checks present/string/bounded and refuses a body
         * field a client may never set (member_id, status, potential_payout). It
         * deliberately does NOT know whether a game exists or a stake is
         * affordable - that is BetSlipService, asserted there, and duplicating it
         * here would give the same question two answers that drift apart.
         */
        $validated = $request->validated();

        try {
            $result = $this->slips->addItem(
                memberId: (int) $user->id,
                gameCode: (string) $validated['game_code'],
                betType: (string) $validated['bet_type'],
                number: (string) $validated['number'],
                stake: (string) $validated['stake'],
                fundingSource: (string) ($validated['funding_source'] ?? BetSlipService::FUNDING_WALLET),
                freeBetReference: $validated['free_bet_voucher_reference'] ?? null,
            );
        } catch (BetSlipRefused $e) {
            return ApiResponse::error($e->reasonCode, $e->getMessage(), $e->status);
        }

        $slip = $result['slip'];

        return ApiResponse::success(
            [
                'slip' => $this->slipPayload($slip),
                'pricing' => $this->pricing->price($slip),
                'merged' => $result['merged'],
            ],
            $result['merged'] ? 'Stake increased on the existing selection.' : 'Selection added.',
            201,
        );
    }

    /**
     * DELETE /me/bet-slip/items/{item}
     */
    public function removeItem(Request $request, int $item): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $slip = $this->slips->removeItem((int) $user->id, $item);

        return ApiResponse::success([
            'slip' => $this->slipPayload($slip),
            'pricing' => $this->pricing->price($slip),
        ], 'Selection removed.');
    }

    /**
     * DELETE /me/bet-slip
     */
    public function clear(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $slip = $this->slips->clear((int) $user->id);

        return ApiResponse::success([
            'slip' => $this->slipPayload($slip),
            'pricing' => $this->pricing->price($slip),
        ], 'Slip cleared.');
    }

    /**
     * POST /me/bet-slip/confirm
     *
     * The idempotency key is read from the header FIRST and the body second, so a
     * client that uses the documented header and one that puts it in the JSON body
     * both work — and a client that sends neither is refused rather than silently
     * buying twice.
     */
    public function confirm(ConfirmBetSlipRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // The header is the documented form; the body field is accepted because
        // both are in the wild. ConfirmBetSlipRequest::idempotencyKey() prefers the
        // header, and refuses a confirm that carries neither.
        $key = $request->idempotencyKey();

        try {
            $result = $this->confirmation->confirm((int) $user->id, $key);
        } catch (BetSlipRefused $e) {
            return ApiResponse::error($e->reasonCode, $e->getMessage(), $e->status);
        }

        return ApiResponse::success(
            [
                'slip' => $this->slipPayload($result['slip']),
                'bets' => $result['bets'],
                'total_stake' => $result['total_stake'],
                'replayed' => $result['replayed'],
            ],
            $result['replayed'] ? 'This slip was already confirmed.' : 'Slip confirmed.',
            $result['replayed'] ? 200 : 201,
        );
    }

    /**
     * The slip as a client sees it.
     *
     * Delegates to BetSlipResource so the slip's public shape exists in exactly one
     * place. The previous private builder was a second definition of the same
     * array, and a field added to one would have been missing from the other.
     *
     * @return array<string, mixed>
     */
    private function slipPayload(\App\Models\BetSlip $slip): array
    {
        /** @var array<string, mixed> $payload */
        $payload = (new BetSlipResource($slip))->resolve();

        return $payload;
    }
}
