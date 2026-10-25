<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Payment;

use App\Http\Responses\ApiResponse;
use App\Services\Payout\ThaiBank\ThaiBankPayoutWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class ThaiBankPayoutWebhookController
{
    public function __construct(private readonly ThaiBankPayoutWebhookService $service) {}

    public function handle(Request $request, string $bank): JsonResponse
    {
        try {
            $result = $this->service->processCallback(
                bank: strtolower($bank),
                rawBody: $request->getContent(),
                headers: $request->headers->all(),
            );
        } catch (Throwable) {
            return ApiResponse::error('payout_webhook_rejected', 'Webhook rejected.', 400);
        }
        if ($result['signature_verified']) {
            $request->attributes->set('webhook.signature_verified', true);
        }
        if (! $result['accepted']) {
            return ApiResponse::error('payout_webhook_rejected', 'Webhook rejected.', 401);
        }

        return ApiResponse::success(['accepted' => true], 'accepted', 200, [
            'duplicate' => (bool) ($result['duplicate'] ?? false),
        ]);
    }
}
