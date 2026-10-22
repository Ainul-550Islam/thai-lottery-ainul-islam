<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Payment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\GeneratePromptPayQrRequest;
use App\Services\Payment\PromptPayQrGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class PromptPayController extends Controller
{
    public function __construct(
        private readonly PromptPayQrGeneratorService $qrGenerator
    ) {}

    public function generate(GeneratePromptPayQrRequest $request): JsonResponse
    {
        $rawAmount = trim((string) $request->input('amount'));
        $amount = bcadd($rawAmount !== '' ? $rawAmount : '0', '0', 2);
        $recipientId = (string) $request->input('recipient_id', env('PROMPTPAY_DEFAULT_MERCHANT_ID', '0812345678'));

        $qrPayload = $this->qrGenerator->generatePayload($recipientId, $amount);
        $referenceId = 'PP-' . strtoupper(Str::random(12));

        return response()->json([
            'success' => true,
            'data' => [
                'reference_id' => $referenceId,
                'recipient_id' => $recipientId,
                'amount' => $amount,
                'currency' => 'THB',
                'qr_raw_payload' => $qrPayload,
                'expires_at' => now()->addMinutes(15)->toIso8601String(),
            ],
        ], Response::HTTP_OK);
    }
}
