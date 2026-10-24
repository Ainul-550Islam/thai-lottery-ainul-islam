<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Contracts\Security\SecurityRiskEngine;
use App\Enums\FraudDecision;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pre-transaction risk gate applied to high-exposure financial endpoints.
 *
 * Supports:
 * - `fraud.mode = 'observe'`: evaluates risk and attaches `fraud_decision_observed`
 *   to request attributes without blocking.
 * - `fraud.mode = 'enforce'` (default): enforces DENY (403), HOLD_FOR_REVIEW (202),
 *   and STEP_UP (403 unless `step_up_verified` attribute is true).
 */
class FraudRiskGateMiddleware
{
    public function __construct(
        private readonly SecurityRiskEngine $riskEngine
    ) {}

    public function handle(Request $request, Closure $next, string $eventType = 'TRANSACTION'): Response
    {
        $user = $request->user();
        if ($user === null) {
            return $next($request);
        }

        $userId = (string) $user->getAuthIdentifier();
        $amount = (string) $request->input('amount', $request->input('stake_amount', '0.0000'));
        $currency = strtoupper((string) $request->input('currency', 'THB'));

        $decision = $this->riskEngine->evaluateTransactionRisk(
            eventType: strtoupper($eventType),
            userId: $userId,
            amount: $amount,
            currency: $currency,
            context: [
                'client_ip' => $request->ip(),
                'route' => $request->route()?->getName() ?? $request->path(),
            ],
        );

        $mode = strtolower((string) config('fraud.mode', 'enforce'));
        if ($mode === 'observe') {
            $request->attributes->set('fraud_decision_observed', $decision);

            return $next($request);
        }

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

        if ($decision->decision === FraudDecision::STEP_UP && $request->attributes->get('step_up_verified') !== true) {
            return response()->json([
                'success' => false,
                'error' => 'TRANSACTION_STEP_UP_REQUIRED',
                'message' => 'Step-up multi-factor verification is required for this transaction.',
                'fraud_alert_id' => $decision->fraudAlertId,
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
