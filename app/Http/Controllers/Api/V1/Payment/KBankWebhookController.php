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
            // A REVIEW-ONLY OUTCOME IS STILL A SUCCESSFULLY PROCESSED
            // NOTIFICATION. `needs_review` and `unmatched` mean the bank's
            // signature was valid and the payload was understood, but no deposit
            // could be credited from it, so the honest answer is HTTP 200 with
            // `credited: false` and the operator-facing `result` left intact for
            // the reconciliation queue. Answering 422 here (as this controller
            // briefly did) tells the bank the notification itself was bad: it
            // hides a valid webhook behind an error status, makes the provider
            // retry a notification that was already accepted, and breaks every
            // caller that reads `result`/`credited` off a 200. Only a thrown
            // exception — an unverifiable signature or an unreadable payload —
            // is a rejection, and that is the 400 branch below.

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
