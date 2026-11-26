<?php

declare(strict_types=1);

namespace App\Services\Fraud;

use App\DTOs\Security\FraudDecisionData;
use App\Models\FraudAlert;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Class FraudEventRecorder
 *
 * Idempotently records fraud alerts and case histories without duplicate collisions.
 */
class FraudEventRecorder
{
    /**
     * Persist the first immutable alert for an event identity, or return it on replay.
     */
    public function recordAlert(
        string $idempotencyKey,
        string $eventType,
        string $userId,
        string $amount,
        string $currency,
        FraudDecisionData $decision
    ): FraudAlert {
        return FraudAlert::firstOrCreate(
            ['idempotency_event_key' => $idempotencyKey],
            [
                'id' => Str::uuid()->toString(),
                'event_type' => $eventType,
                'user_id' => $userId,
                // A resolved pointer beside the raw reference. Only set when the subject really
                // is a numeric account id — a uuid (or an external reference) stays NULL rather
                // than being coerced into a link to the wrong account.
                'player_user_id' => $this->resolvePlayerId($userId),
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

    /**
     * The account id this subject reference points at, or null.
     *
     * Deliberately an existence check rather than a cast: `(int) 'abc'` is 0 and `(int) '12abc'`
     * is 12, and linking an alert to account 12 because of the second one would be a worse bug
     * than showing no link at all.
     */
    private function resolvePlayerId(string $userId): ?int
    {
        if (preg_match('/^[0-9]{1,18}$/', $userId) !== 1) {
            return null;
        }

        $candidate = (int) $userId;

        return $candidate > 0 && User::query()->whereKey($candidate)->exists() ? $candidate : null;
    }
}
