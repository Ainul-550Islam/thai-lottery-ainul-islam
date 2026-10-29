<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Payment;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Payment\DepositMethodsPageController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Legacy adapter: never manufacture a QR or accept a client-owned recipient.
 * The canonical controller derives the wallet and merchant from the actor and
 * PaymentGatewayManager, and creates the durable checkout. Any route using this
 * adapter must apply the same auth, age and legal middleware as that endpoint.
 */
class PromptPayController extends Controller
{
    public function __construct(private readonly DepositMethodsPageController $deposits) {}

    public function generate(Request $request): JsonResponse
    {
        return $this->deposits->generatePromptPayApi($request);
    }
}
