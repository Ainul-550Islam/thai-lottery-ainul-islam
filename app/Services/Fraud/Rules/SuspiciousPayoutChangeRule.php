<?php

declare(strict_types=1);

namespace App\Services\Fraud\Rules;

use Illuminate\Support\Facades\DB;

/**
 * Class SuspiciousPayoutChangeRule
 *
 * Detects account takeover signatures: Payout destination bank account altered shortly before large withdrawal request.
 */
class SuspiciousPayoutChangeRule
{
    private const CRITICAL_WINDOW_HOURS = 24;

    public function evaluate(string $eventType, string $userId, string $amount, array $context = []): array
    {
        if ($eventType !== 'WITHDRAWAL') {
            return ['triggered' => false, 'rule_id' => 'RECENT_BANK_DESTINATION_CHANGE', 'risk_points' => 0];
        }

        // Check if bank account changed in last 24 hours
        $recentAudit = DB::table('security_audit_logs')
            ->where('user_id', $userId)
            ->where('event_type', 'BANK_ACCOUNT_UPDATED')
            ->where('occurred_at', '>=', now()->subHours(self::CRITICAL_WINDOW_HOURS))
            ->exists();

        if ($recentAudit && bccomp($amount, '50000.0000', 4) >= 0) {
            return [
                'triggered' => true,
                'rule_id' => 'RECENT_BANK_DESTINATION_CHANGE',
                'risk_points' => 80,
                'reason' => 'Large withdrawal requested within 24 hours of modifying bank disbursement account.',
            ];
        }

        return ['triggered' => false, 'rule_id' => 'RECENT_BANK_DESTINATION_CHANGE', 'risk_points' => 0];
    }
}
