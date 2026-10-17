<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Payment;

use App\Http\Controllers\Controller;
use App\Services\Payment\KasikornBankWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class KBankWebhookController extends Controller
{
    public function __construct(
        private readonly KasikornBankWebhookService $kbankWebhookService
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $signature = (string) $request->header('X-KBANK-Signature', '');
        $clientIp = (string) $request->ip();
        $payload = $request->all();

        try {
            $result = $this->kbankWebhookService->processWebhook($payload, $signature, $clientIp);

            return response()->json([
                'status' => 'SUCCESS',
                'tx_id' => $result['trans_ref'],
                'message' => 'Notification processed successfully.',
            ], Response::HTTP_OK);
        } catch (Throwable $e) {
            return response()->json([
                'status' => 'ERROR',
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }
}
