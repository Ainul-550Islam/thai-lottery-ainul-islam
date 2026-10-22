<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Deposit\DepositProcessingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class DepositWebhookController extends Controller
{
    public function __construct(
        private readonly DepositProcessingService $depositService
    ) {}

    /**
     * Ingest signed payment gateway webhook callbacks.
     */
    public function handleWebhook(Request $request, string $provider): JsonResponse
    {
        $payload = $request->all();

        $userId = (string) ($payload['user_id'] ?? $payload['custom_fields']['user_id'] ?? '');
        $amount = (string) ($payload['amount'] ?? '0.0000');
        $referenceId = (string) ($payload['transaction_id'] ?? $payload['reference'] ?? '');
        $currency = (string) ($payload['currency'] ?? 'THB');
        $feeAmount = (string) ($payload['fee'] ?? '0.0000');

        if (!$userId || !$referenceId || bccomp($amount, '0.0000', 4) <= 0) {
            return response()->json([
                'success' => false,
                'error' => 'INVALID_WEBHOOK_PAYLOAD',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $deposit = $this->depositService->processConfirmedDeposit(
                userId: $userId,
                amount: $amount,
                gatewayProvider: strtoupper($provider),
                gatewayReferenceId: $referenceId,
                currency: $currency,
                feeAmount: $feeAmount,
                rawPayload: $payload
            );

            return response()->json([
                'success' => true,
                'status' => 'DEPOSIT_PROCESSED',
                'deposit_id' => $deposit->id,
                'deposit_number' => $deposit->deposit_number,
            ], Response::HTTP_OK);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => 'PROCESSING_FAILED',
                'message' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
