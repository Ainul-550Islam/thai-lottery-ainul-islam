<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Payment;

use App\Exceptions\FinancialException;
use App\Http\Requests\Payment\RedeemTruemoneyVoucherRequest;
use App\Http\Responses\ApiResponse;
use App\Http\Support\ApiErrorEnvelope;
use App\Models\User;
use App\Services\Payment\TrueMoneyWalletService;
use Illuminate\Http\JsonResponse;

final class TruemoneyVoucherController
{
    public function __construct(private readonly TrueMoneyWalletService $service) {}

    public function redeem(RedeemTruemoneyVoucherRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return ApiResponse::error('unauthenticated', 'Authentication is required.', 401);
        }
        try {
            $result = $this->service->redeemVoucher(
                user: $user,
                voucherUrl: (string) $request->validated('voucher_url'),
                clientIp: (string) ($request->ip() ?? ''),
            );

            return ApiResponse::success([
                'redemption_id' => $result['redemption_id'],
                'deposit_reference' => $result['deposit_reference'],
                'amount' => $result['amount'],
                'currency' => 'THB',
                'status' => 'credited',
            ], 'Voucher redeemed and deposit credited.', 201);
        } catch (FinancialException $exception) {
            [$code, $message, $status] = $this->mapError($exception->errorCode());

            return ApiErrorEnvelope::error(['code' => $code, 'message' => $message, 'status' => $status, 'details' => []]);
        }
    }

    /** @return array{string, string, int} */
    private function mapError(?string $errorCode): array
    {
        return match ($errorCode) {
            'truemoney_not_configured', 'truemoney_api_not_acknowledged' => [
                $errorCode, 'TrueMoney voucher redemption is currently unavailable.', 503,
            ],
            'truemoney_voucher_invalid' => ['truemoney_voucher_invalid', 'The voucher URL is invalid.', 422],
            'truemoney_voucher_already_processed' => ['truemoney_voucher_already_processed', 'This voucher has already been submitted.', 409],
            'truemoney_daily_count_limit', 'truemoney_daily_amount_limit', 'truemoney_fraud_denied' => [
                $errorCode, 'Voucher redemption was refused by account and risk controls.', 422,
            ],
            'truemoney_outcome_unknown', 'truemoney_credit_unconfirmed', 'truemoney_review_required' => [
                $errorCode, 'The voucher is being held for reconciliation; do not submit it again.', 409,
            ],
            default => ['truemoney_processing_failed', 'The voucher could not be processed safely.', 422],
        };
    }
}
