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
 *
 * TWO MODES, ONE ENGINE
 * config('fraud.mode') decides what the gate may do with the engine's verdict:
 *  - 'enforce' (default): DENY is refused, HOLD_FOR_REVIEW is parked, and STEP_UP
 *    is refused with TRANSACTION_STEP_UP_REQUIRED unless the request already
 *    carries a verified step-up (attributes['step_up_verified']).
 *  - 'observe': nothing is blocked. The verdict is attached to the request as
 *    fraud_decision_observed so telemetry and shadow runs can be measured before
 *    the policy is enforced.
 *
 * STEP_UP AND OBSERVE WERE MISSING ENTIRELY
 * Only DENY and HOLD_FOR_REVIEW were handled, so a STEP_UP verdict - the engine's
 * answer for "correct credentials, unverified device" - fell straight through to
 * the money-moving controller, and 'observe' mode blocked live traffic. Both are
 * fixed here rather than at the call sites, so every guarded route behaves the
 * same way.
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

        $enforcing = strtolower((string) config('fraud.mode', 'enforce')) === 'enforce';

        if (! $enforcing) {
            // Observe mode is telemetry only: attach the verdict, never intercept.
            $request->attributes->set('fraud_decision_observed', $decision);

            return $next($request);
        }

        if ($decision->decision === FraudDecision::STEP_UP) {
            if ($request->attributes->get('step_up_verified') === true) {
                return $next($request);
            }

            return response()->json([
                'success' => false,
                'error' => 'TRANSACTION_STEP_UP_REQUIRED',
                'message' => 'Additional verification is required before this transaction can proceed.',
                'reason_codes' => $decision->reasonCodes,
                'fraud_alert_id' => $decision->fraudAlertId,
            ], Response::HTTP_FORBIDDEN);
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

        return $next($request);
    }
}
