<?php

declare(strict_types=1);

namespace App\Contracts\Security;

use App\DTOs\Security\FraudDecisionData;
use Illuminate\Http\Request;

/**
 * Interface SecurityRiskEngine
 *
 * Contract for fraud and financial transaction risk evaluation engines.
 * A risk engine MUST NEVER directly mutate ledger balances or ticket states.
 */
interface SecurityRiskEngine
{
    /**
     * Evaluate transaction risk and return an explainable decision.
     *
     * @param string $eventType E.g. DEPOSIT, WITHDRAWAL, BET_PURCHASE, PAYOUT_CLAIM
     * @param string $userId
     * @param string $amount
     * @param string $currency
     * @param array<string, mixed> $context Additional metadata (IP, device token, payment ref)
     * @return FraudDecisionData
     */
    public function evaluateTransactionRisk(
        string $eventType,
        string $userId,
        string $amount,
        string $currency,
        array $context = []
    ): FraudDecisionData;

    /**
     * Reassess an existing pending fraud alert idempotently.
     *
     * @param string $fraudAlertId
     * @return FraudDecisionData
     */
    public function reassessAlert(string $fraudAlertId): FraudDecisionData;
}
