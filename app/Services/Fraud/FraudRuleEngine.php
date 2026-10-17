<?php

declare(strict_types=1);

namespace App\Services\Fraud;

use App\Services\Fraud\Rules\AccountLinkageRule;
use App\Services\Fraud\Rules\DuplicateReferenceRule;
use App\Services\Fraud\Rules\KycMismatchRule;
use App\Services\Fraud\Rules\RapidDepositWithdrawalRule;
use App\Services\Fraud\Rules\SuspiciousPayoutChangeRule;
use App\Services\Fraud\Rules\TransactionVelocityRule;

/**
 * Class FraudRuleEngine
 *
 * Ordered, deterministic evaluation of domain-specific financial fraud rules.
 */
class FraudRuleEngine
{
    private array $rules;

    public function __construct()
    {
        $this->rules = [
            new DuplicateReferenceRule(),
            new SuspiciousPayoutChangeRule(),
            new RapidDepositWithdrawalRule(),
            new AccountLinkageRule(),
            new KycMismatchRule(),
            new TransactionVelocityRule(),
        ];
    }

    /**
     * Run all rules and return cumulative score and triggered rule summaries.
     *
     * @return array{score: int, reason_codes: array<int, string>, triggered_rules: array<int, array{rule_id: string, risk_points: int, reason: string}>}
     */
    public function evaluateAll(string $eventType, string $userId, string $amount, array $context = []): array
    {
        $totalRiskPoints = 0;
        $reasonCodes = [];
        $triggeredRules = [];

        foreach ($this->rules as $rule) {
            $result = $rule->evaluate($eventType, $userId, $amount, $context);

            if ($result['triggered']) {
                $totalRiskPoints += $result['risk_points'];
                $reasonCodes[] = $result['rule_id'];
                $triggeredRules[] = [
                    'rule_id' => $result['rule_id'],
                    'risk_points' => $result['risk_points'],
                    'reason' => $result['reason'] ?? '',
                ];
            }
        }

        return [
            'score' => min(100, $totalRiskPoints),
            'reason_codes' => array_values(array_unique($reasonCodes)),
            'triggered_rules' => $triggeredRules,
        ];
    }
}
