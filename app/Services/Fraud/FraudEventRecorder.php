<?php

declare(strict_types=1);

namespace App\Services\Fraud;

use App\DTOs\Security\FraudDecisionData;
use App\Models\FraudAlert;
use Illuminate\Support\Str;

/**
 * Class FraudEventRecorder
 *
 * Idempotently records fraud alerts and case histories without duplicate collisions.
 */
class FraudEventRecorder
{
    /**
     * Persist or update a fraud alert.
     */
    public function recordAlert(
        string $idempotencyKey,
        string $eventType,
        string $userId,
        string $amount,
        string $currency,
        FraudDecisionData $decision
    ): FraudAlert {
        return FraudAlert::updateOrCreate(
            ['idempotency_event_key' => $idempotencyKey],
            [
                'id' => Str::uuid()->toString(),
                'event_type' => $eventType,
                'user_id' => $userId,
                'amount' => $amount,
                'currency' => $currency,
                'risk_score' => $decision->riskScore,
                'decision' => $decision->decision->value,
                'reason_codes' => $decision->reasonCodes,
                'triggered_rules' => $decision->triggeredRules,
                'status' => $decision->decision->requiresReview() ? 'OPEN' : 'RESOLVED_ALLOW',
            ]
        );
    }
}
