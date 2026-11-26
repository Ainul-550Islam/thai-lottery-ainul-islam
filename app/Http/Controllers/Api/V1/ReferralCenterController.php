<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\FinancialException;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\WithdrawalException;
use App\Http\Requests\Api\V1\ReferralPayoutRequest;
use App\Http\Resources\ReferralCenterResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Affiliate\ReferralCenterService;
use App\Services\Affiliate\ReferralPayoutRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReferralCenterController
{
    public function __construct(
        private readonly ReferralCenterService $referralCenter,
        private readonly ReferralPayoutRequestService $payoutRequests,
    ) {}

    public function show(Request $request): JsonResponse
    {
        /** @var User $member */
        $member = $request->user();
        $perPage = (int) $request->integer('per_page', (int) config('rewards.referral.team_per_page', 20));
        $readModel = $this->referralCenter->readModelFor($member, $perPage);
        $payload = (new ReferralCenterResource($readModel))->resolve($request);

        return ApiResponse::success($payload, 'Referral center retrieved.');
    }

    public function requestPayout(ReferralPayoutRequest $request): JsonResponse
    {
        /** @var User $member */
        $member = $request->user();
        $validated = $request->validated();

        try {
            $result = $this->payoutRequests->requestPayout(
                member: $member,
                amount: (string) $validated['amount'],
                idempotencyKey: $request->idempotencyKey(),
                bankAccountId: isset($validated['member_bank_account_id']) ? (int) $validated['member_bank_account_id'] : null,
                currencyCode: isset($validated['currency']) && is_string($validated['currency']) ? $validated['currency'] : null,
            );
        } catch (InsufficientBalanceException) {
            return ApiResponse::error(
                code: 'insufficient_balance',
                message: 'Available commission wallet balance is insufficient for this payout request.',
                status: 422,
            );
        } catch (WithdrawalException|FinancialException $exception) {
            return ApiResponse::error(
                code: $exception->errorCode() ?? 'referral_payout_error',
                message: $exception->getMessage(),
                status: 422,
            );
        }

        $withdrawal = $result['withdrawal'];

        return ApiResponse::success(
            data: [
                'withdrawal' => [
                    'id' => (int) $withdrawal->getKey(),
                    'reference_number' => $withdrawal->reference_number,
                    'status' => $withdrawal->status->value,
                    'amount' => (string) $withdrawal->amount,
                    'fee' => (string) $withdrawal->fee,
                    'net_amount' => (string) $withdrawal->net_amount,
                    'currency' => $withdrawal->currency->value,
                    'member_bank_account_id' => $withdrawal->member_bank_account_id,
                    'requested_at' => $withdrawal->requested_at?->toIso8601String(),
                ],
                'replayed' => $result['replayed'],
            ],
            message: $result['replayed'] ? 'Referral payout request replayed.' : 'Referral payout requested successfully.',
            status: $result['replayed'] ? 200 : 201,
        );
    }
}
