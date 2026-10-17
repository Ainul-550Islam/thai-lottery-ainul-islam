<?php

declare(strict_types=1);

namespace App\Services\Fraud\Rules;

use Illuminate\Support\Facades\DB;

/**
 * Class KycMismatchRule
 *
 * Checks if payout recipient account name deviates significantly from verified user profile.
 */
class KycMismatchRule
{
    public function evaluate(string $eventType, string $userId, string $amount, array $context = []): array
    {
        $accountName = trim((string) ($context['account_name'] ?? ''));

        if ($eventType === 'WITHDRAWAL' && $accountName !== '') {
            $userKycName = (string) DB::table('users')->where('id', $userId)->value('name');

            if ($userKycName !== '' && levenshtein(strtolower($userKycName), strtolower($accountName)) > 5) {
                return [
                    'triggered' => true,
                    'rule_id' => 'BENEFICIARY_NAME_MISMATCH',
                    'risk_points' => 50,
                    'reason' => 'Withdrawal beneficiary name does not match verified account holder name.',
                ];
            }
        }

        return ['triggered' => false, 'rule_id' => 'BENEFICIARY_NAME_MISMATCH', 'risk_points' => 0];
    }
}
