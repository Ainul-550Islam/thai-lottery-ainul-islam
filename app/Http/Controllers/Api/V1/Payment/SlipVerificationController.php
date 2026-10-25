<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Payment;

use App\Exceptions\FinancialException;
use App\Http\Requests\Payment\VerifySlipRequest;
use App\Http\Responses\ApiResponse;
use App\Http\Support\ApiErrorEnvelope;
use App\Models\Deposit;
use App\Models\DepositSlipVerification;
use App\Models\User;
use App\Services\Payment\BankSlipVerificationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class SlipVerificationController
{
    public function __construct(private readonly BankSlipVerificationService $service) {}

    public function store(VerifySlipRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return ApiResponse::error('unauthenticated', 'Authentication is required.', 401);
        }
        $validated = $request->validated();
        $deposit = null;
        if (is_string($validated['deposit_reference'] ?? null)) {
            $deposit = Deposit::query()
                ->where('user_id', $user->getKey())
                ->where('reference_number', $validated['deposit_reference'])
                ->first();
            if (! $deposit instanceof Deposit) {
                return ApiResponse::error('deposit_not_found', 'The matching deposit request was not found.', 404);
            }
        }
        try {
            $verification = $this->service->verify(
                user: $user,
                image: $request->file('slip_image'),
                qrPayload: is_string($validated['qr_payload'] ?? null) ? $validated['qr_payload'] : null,
                claimedAmount: (string) $validated['amount'],
                clientIp: (string) ($request->ip() ?? ''),
                deposit: $deposit,
            );

            return ApiResponse::success([
                'verification_id' => (int) $verification->getKey(),
                'status' => $verification->status->value,
                'reason_code' => $this->safeReasonCode($verification->review_reason),
                'amount' => (string) $verification->amount,
                'currency' => (string) $verification->currency,
            ], 'Slip verification recorded.', 201);
        } catch (FinancialException $exception) {
            [$code, $message, $status] = $this->mapError($exception->errorCode());

            return ApiErrorEnvelope::error(['code' => $code, 'message' => $message, 'status' => $status, 'details' => []]);
        }
    }

    public function show(DepositSlipVerification $verification, Request $request): JsonResponse
    {
        try {
            Gate::authorize('view', $verification);
        } catch (AuthorizationException) {
            return ApiResponse::error('slip_verification_not_found', 'Slip verification not found.', 404);
        }

        return ApiResponse::success([
            'verification_id' => (int) $verification->getKey(),
            'status' => $verification->status->value,
            'reason_code' => $this->safeReasonCode($verification->review_reason),
            'amount' => (string) $verification->amount,
            'currency' => (string) $verification->currency,
            'created_at' => $verification->created_at?->toIso8601String(),
            'reviewed_at' => $verification->reviewed_at?->toIso8601String(),
        ], 'Slip verification retrieved.');
    }

    private function safeReasonCode(?string $reason): ?string
    {
        return in_array($reason, [
            'upload_scan_not_clean', 'quarantined_file_unavailable', 'provider_unreachable',
            'provider_not_configured', 'provider_rejected_slip', 'provider_error', 'provider_invalid',
            'duplicate', 'provider_duplicate', 'trans_ref_already_seen', 'amount_mismatch', 'provider_amount_invalid',
            'currency_mismatch', 'receiver_mismatch', 'expired', 'slip_expired', 'future_dated', 'fraud_denied',
            'deposit_mismatch', 'fraud_check_unavailable', 'manual_review_required', 'matching_pending_deposit_not_found',
            'automatic_credit_pending', 'deposit_completion_failed',
        ], true) ? $reason : null;
    }

    /** @return array{string, string, int} */
    private function mapError(?string $errorCode): array
    {
        return match ($errorCode) {
            'slip_verification_not_configured', 'slip_provider_not_configured' => [
                'slip_verification_not_configured', 'Slip verification is currently unavailable.', 503,
            ],
            'slip_invalid', 'slip_upload_rejected' => ['slip_invalid', 'The slip input could not be safely verified.', 422],
            'slip_credit_unconfirmed' => ['slip_credit_unconfirmed', 'The verified slip requires manual review before any further action.', 409],
            default => ['slip_verification_failed', 'The slip could not be processed safely.', 422],
        };
    }
}
