<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Withdrawal\WithdrawalProcessingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class WithdrawalController extends Controller
{
    public function __construct(
        private readonly WithdrawalProcessingService $withdrawalService
    ) {}

    /**
     * Submit a withdrawal request from authenticated user.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => ['required', 'numeric', 'min:100.00', 'max:500000.00'],
            'payout_channel' => ['required', 'string', 'in:BANK_TRANSFER,PROMPTPAY,USDT'],
            'account_name' => ['required', 'string', 'min:2', 'max:128'],
            'account_number' => ['required', 'string', 'min:5', 'max:64'],
            'bank_code' => ['nullable', 'string', 'max:32'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'VALIDATION_FAILED',
                'messages' => $validator->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $userId = (string) $request->user()?->id ?? (string) $request->input('user_id');

        try {
            $withdrawal = $this->withdrawalService->requestWithdrawal(
                userId: $userId,
                amount: number_format((float) $request->input('amount'), 4, '.', ''),
                payoutChannel: (string) $request->input('payout_channel'),
                accountName: (string) $request->input('account_name'),
                accountNumber: (string) $request->input('account_number'),
                bankCode: $request->input('bank_code') ? (string) $request->input('bank_code') : null,
                currency: 'THB'
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $withdrawal->id,
                    'withdrawal_number' => $withdrawal->withdrawal_number,
                    'amount' => $withdrawal->amount,
                    'status' => $withdrawal->status,
                    'created_at' => $withdrawal->created_at?->toIso8601String(),
                ],
            ], Response::HTTP_CREATED);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => 'WITHDRAWAL_REQUEST_REJECTED',
                'message' => $e->getMessage(),
            ], Response::HTTP_CONFLICT);
        }
    }
}
