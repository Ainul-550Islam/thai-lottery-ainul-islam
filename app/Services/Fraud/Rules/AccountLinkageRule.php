<?php

declare(strict_types=1);

namespace App\Services\Fraud\Rules;

use Illuminate\Support\Facades\DB;

/**
 * Class AccountLinkageRule
 *
 * Identifies multi-accounting fraud by checking shared bank accounts or payout destination collisions.
 */
class AccountLinkageRule
{
    public function evaluate(string $eventType, string $userId, string $amount, array $context = []): array
    {
        $accountNumber = $context['account_number'] ?? null;

        if ($accountNumber) {
            $otherUsersCount = DB::table('withdrawal_requests')
                ->where('account_number', $accountNumber)
                ->where('user_id', '!=', $userId)
                ->distinct('user_id')
                ->count('user_id');

            if ($otherUsersCount >= 2) {
                return [
                    'triggered' => true,
                    'rule_id' => 'LINKED_BANK_ACCOUNT_ACROSS_USERS',
                    'risk_points' => 75,
                    'reason' => 'Bank destination account is shared across multiple distinct user profiles.',
                ];
            }
        }

        return ['triggered' => false, 'rule_id' => 'LINKED_BANK_ACCOUNT_ACROSS_USERS', 'risk_points' => 0];
    }
}
