<?php

declare(strict_types=1);

namespace App\Services\Fraud\Rules;

use App\Models\DepositTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Class RapidDepositWithdrawalRule
 *
 * Detects classic money laundering patterns: deposit followed by immediate withdrawal without betting turnover.
 */
class RapidDepositWithdrawalRule
{
    private const RAPID_WINDOW_MINUTES = 30;

    public function evaluate(string $eventType, string $userId, string $amount, array $context = []): array
    {
        if ($eventType !== 'WITHDRAWAL') {
            return ['triggered' => false, 'rule_id' => 'RAPID_DEPOSIT_WITHDRAWAL', 'risk_points' => 0];
        }

        $recentDeposit = DepositTransaction::where('user_id', $userId)
            ->where('status', 'COMPLETED')
            ->where('completed_at', '>=', now()->subMinutes(self::RAPID_WINDOW_MINUTES))
            ->orderBy('completed_at', 'desc')
            ->first();

        if ($recentDeposit) {
            // Check if betting turnover during this window was less than 50% of deposit
            $turnover = DB::table('lottery_entries')
                ->where('user_id', $userId)
                ->where('placed_at', '>=', $recentDeposit->completed_at)
                ->sum('stake_amount') ?? '0.0000';

            $requiredTurnover = bcmul((string) $recentDeposit->net_amount, '0.50', 4);

            if (bccomp((string) $turnover, $requiredTurnover, 4) < 0) {
                return [
                    'triggered' => true,
                    'rule_id' => 'RAPID_DEPOSIT_WITHDRAWAL',
                    'risk_points' => 60,
                    'reason' => 'Immediate withdrawal requested shortly after deposit without sufficient betting turnover.',
                ];
            }
        }

        return ['triggered' => false, 'rule_id' => 'RAPID_DEPOSIT_WITHDRAWAL', 'risk_points' => 0];
    }
}
