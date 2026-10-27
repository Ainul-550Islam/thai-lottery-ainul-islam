<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Payment;

use App\Http\Controllers\Controller;
use App\Services\Payment\KasikornBankWebhookService;
use App\Services\Payment\PaymentLogSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class KBankWebhookController extends Controller
{
    public function __construct(
        private readonly KasikornBankWebhookService $kbankWebhookService,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        try {
            $result = $this->kbankWebhookService->processWebhook(
                $request->json()->all(),
                (string) $request->header('X-KBANK-Signature', ''),
                (string) $request->ip(),
                $request->header('X-KBANK-Timestamp') ?? $request->header('X-Timestamp'),
            );
            $request->attributes->set('webhook.signature_verified', true);
            if (in_array((string) ($result['result'] ?? ''), ['needs_review', 'unmatched'], true)) {
                return response()->json(['status' => 'ERROR', 'message' => trans('payment_ops.webhook_match_failed')], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return response()->json([
                'status' => 'SUCCESS',
                'result' => $result['result'],
                'credited' => $result['credited'],
                'tx_id' => $result['trans_ref'],
                'message' => 'Notification processed successfully.',
            ], Response::HTTP_OK);
        } catch (Throwable $exception) {
            Log::warning('KBank webhook notification rejected.', PaymentLogSanitizer::sanitize([
                'exception' => $exception::class,
                'reason' => $exception->getMessage(),
                'payload' => $request->json()->all(),
                'signature' => $request->header('X-KBANK-Signature'),
            ]));

            return response()->json([
                'status' => 'ERROR',
                'message' => 'Notification rejected.',
            ], Response::HTTP_BAD_REQUEST);
        }
    }
}
