<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Contracts\Security\SecurityRiskEngine;
use App\Enums\FraudDecision;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class FraudRiskGateMiddleware
 *
 * Pre-transaction risk gate applied to high-exposure financial endpoints.
 */
class FraudRiskGateMiddleware
{
    public function __construct(
        private readonly SecurityRiskEngine $riskEngine
    ) {}

    public function handle(Request $request, Closure $next, string $eventType = 'TRANSACTION'): Response
    {
        $userId = (string) ($request->user()?->id ?? $request->input('user_id', ''));
        $amount = (string) $request->input('amount', '0.0000');
        $currency = (string) $request->input('currency', 'THB');

        if ($userId === '') {
            return $next($request);
        }

        $decision = $this->riskEngine->evaluateTransactionRisk(
            eventType: strtoupper($eventType),
            userId: $userId,
            amount: $amount,
            currency: $currency,
            context: [
                'client_ip' => $request->ip(),
                'account_number' => (string) $request->input('account_number', ''),
                'account_name' => (string) $request->input('account_name', ''),
            ]
        );

        if ($decision->decision === FraudDecision::DENY) {
            return response()->json([
                'success' => false,
                'error' => 'TRANSACTION_REJECTED_RISK',
                'message' => 'Transaction was declined by automated security policy.',
                'reason_codes' => $decision->reasonCodes,
            ], Response::HTTP_FORBIDDEN);
        }

        if ($decision->decision === FraudDecision::HOLD_FOR_REVIEW) {
            return response()->json([
                'success' => false,
                'error' => 'TRANSACTION_HELD_FOR_REVIEW',
                'message' => 'Transaction is held for administrative compliance review.',
                'fraud_alert_id' => $decision->fraudAlertId,
            ], Response::HTTP_ACCEPTED);
        }

        return $next($request);
    }
}
