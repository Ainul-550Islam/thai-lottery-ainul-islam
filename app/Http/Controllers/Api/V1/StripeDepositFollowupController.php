<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\FinancialException;
use App\Services\Payment\StripeDepositFollowupInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Separate raw Stripe endpoint: acknowledges durable observation, never successful financial application. */
final class StripeDepositFollowupController
{
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $result = app(StripeDepositFollowupInbox::class)->receive($request->getContent(), $request->header('Stripe-Signature'));

            return response()->json(['recorded' => true, 'replayed' => $result['replayed'], 'financial_application' => false], 200);
        } catch (FinancialException $e) {
            $status = match ($e->errorCode()) {
                'stripe_followup_signature' => 403,
                'stripe_followup_conflict' => 409,
                'stripe_review_disabled' => 503,
                default => 422,
            };

            return response()->json(['recorded' => false, 'code' => $e->errorCode()], $status);
        }
    }
}
