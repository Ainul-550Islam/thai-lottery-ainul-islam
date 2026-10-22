<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Payment;

use App\Http\Controllers\Controller;
use App\Services\Payment\ScbOpenApiWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ScbWebhookController extends Controller
{
    public function __construct(
        private readonly ScbOpenApiWebhookService $scbWebhookService
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $signature = (string) $request->header('X-SCB-Signature', '');
        $clientIp = (string) $request->ip();
        $payload = $request->all();

        try {
            $result = $this->scbWebhookService->processWebhook($payload, $signature, $clientIp);

            return response()->json([
                'resCode' => '00',
                'resDesc' => 'success',
                'transactionId' => $result['trans_ref'],
            ], Response::HTTP_OK);
        } catch (Throwable $e) {
            return response()->json([
                'resCode' => '99',
                'resDesc' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }
}
