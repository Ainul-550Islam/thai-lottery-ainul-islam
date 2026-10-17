<?php

declare(strict_types=1);

namespace App\DTOs\Security;

use App\Enums\FraudDecision;

/**
 * Class FraudDecisionData
 *
 * Immutable data transfer object containing fraud evaluation decisions.
 */
readonly class FraudDecisionData
{
    /**
     * @param int $riskScore Fraud score (0 to 100)
     * @param FraudDecision $decision Action (allow, step_up, hold_for_review, deny)
     * @param array<int, string> $reasonCodes List of violated rule codes (e.g. VELOCITY_LIMIT_EXCEEDED)
     * @param string|null $fraudAlertId Database ID of the created alert/case if flagged
     * @param array<string, mixed> $triggeredRules Detailed rule context
     * @param string $evaluatedAt ISO8601 timestamp
     */
    public function __construct(
        public int $riskScore,
        public FraudDecision $decision,
        public array $reasonCodes,
        public ?string $fraudAlertId = null,
        public array $triggeredRules = [],
        public string $evaluatedAt = ''
    ) {}

    public function toArray(): array
    {
        return [
            'risk_score' => $this->riskScore,
            'decision' => $this->decision->value,
            'reason_codes' => $this->reasonCodes,
            'fraud_alert_id' => $this->fraudAlertId,
            'triggered_rules' => $this->triggeredRules,
            'evaluated_at' => $this->evaluatedAt ?: date('c'),
        ];
    }
}
