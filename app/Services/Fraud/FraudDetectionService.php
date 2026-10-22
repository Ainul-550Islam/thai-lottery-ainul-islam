<?php

declare(strict_types=1);

namespace App\Services\Fraud;

use App\Contracts\Security\SecurityRiskEngine;
use App\DTOs\Security\FraudDecisionData;
use App\Enums\FraudDecision;
use App\Events\FraudReviewRequired;
use App\Events\FraudSuspected;
use App\Models\FraudAlert;
use Illuminate\Support\Str;

/**
 * Class FraudDetectionService
 *
 * Implements SecurityRiskEngine contract for financial risk scoring and alert dispatch.
 */
class FraudDetectionService implements SecurityRiskEngine
{
    public function __construct(
        private readonly FraudRuleEngine $ruleEngine,
        private readonly FraudEventRecorder $eventRecorder
    ) {}

    /**
     * @param  string|array<string, mixed>  $eventType
     * @param  array<string, mixed>  $context
     */
    public function evaluateTransactionRisk(
        string|array $eventType,
        string $userId = '',
        string $amount = '0.0000',
        string $currency = 'THB',
        array $context = []
    ): FraudDecisionData {
        if (is_array($eventType)) {
            $payload = $eventType;
            $eventType = (string) ($payload['event_type'] ?? 'UNKNOWN');
            $userId = (string) ($payload['user_id'] ?? $userId);
            $amount = (string) ($payload['amount'] ?? $amount);
            $currency = (string) ($payload['currency'] ?? $currency);
            $context = array_merge($payload, $context);
        }
        $evaluation = $this->ruleEngine->evaluateAll($eventType, $userId, $amount, $context);
        $score = $evaluation['score'];

        $reviewThreshold = (int) config('fraud.review_threshold', 60);
        $denyThreshold = (int) config('fraud.deny_threshold', 90);

        $decision = match (true) {
            $score >= $denyThreshold => FraudDecision::DENY,
            $score >= $reviewThreshold => FraudDecision::HOLD_FOR_REVIEW,
            $score >= 40 => FraudDecision::STEP_UP,
            default => FraudDecision::ALLOW,
        };

        $idempotencyKey = "FRAUD-{$eventType}-{$userId}-".($context['reference_id'] ?? Str::random(8));

        $decisionData = new FraudDecisionData(
            riskScore: $score,
            decision: $decision,
            reasonCodes: $evaluation['reason_codes'],
            triggeredRules: $evaluation['triggered_rules'],
            evaluatedAt: date('c')
        );

        if ($decision !== FraudDecision::ALLOW) {
            $alert = $this->eventRecorder->recordAlert(
                idempotencyKey: $idempotencyKey,
                eventType: $eventType,
                userId: $userId,
                amount: $amount,
                currency: $currency,
                decision: $decisionData
            );

            // Reconstruct with alert ID
            $decisionData = new FraudDecisionData(
                riskScore: $score,
                decision: $decision,
                reasonCodes: $evaluation['reason_codes'],
                fraudAlertId: $alert->id,
                triggeredRules: $evaluation['triggered_rules'],
                evaluatedAt: date('c')
            );

            if ($decision === FraudDecision::HOLD_FOR_REVIEW) {
                event(new FraudReviewRequired($alert->id, $eventType, $userId, $amount, $decisionData));
            } else {
                event(new FraudSuspected($eventType, $userId, $amount, $currency, $decisionData));
            }
        }

        return $decisionData;
    }

    public function reassessAlert(string $fraudAlertId): FraudDecisionData
    {
        /** @var FraudAlert $alert */
        $alert = FraudAlert::findOrFail($fraudAlertId);

        return $this->evaluateTransactionRisk(
            eventType: $alert->event_type,
            userId: (string) $alert->user_id,
            amount: (string) $alert->amount,
            currency: (string) $alert->currency,
            context: ['reference_id' => $alert->idempotency_event_key]
        );
    }
}
