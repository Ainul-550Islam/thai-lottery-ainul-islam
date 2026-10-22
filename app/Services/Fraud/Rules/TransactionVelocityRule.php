<?php

declare(strict_types=1);

namespace App\Services\Fraud\Rules;

use Illuminate\Support\Facades\DB;

/**
 * Class TransactionVelocityRule
 *
 * Flags anomalous transaction count or volume within a rolling 60-minute window.
 */
class TransactionVelocityRule
{
    private const HOURLY_LIMIT_THB = '500000.0000';

    private const HOURLY_COUNT_LIMIT = 50;

    public function evaluate(string $eventType, string $userId, string $amount, array $context = []): array
    {
        $oneHourAgo = now()->subHour();

        $recentTransactions = DB::table('ledger_transactions')
            ->where('posted_at', '>=', $oneHourAgo)
            ->where('description', 'like', "%{$userId}%")
            ->selectRaw('COUNT(*) as total_count, SUM(total_amount) as total_volume')
            ->first();

        $count = (int) ($recentTransactions->total_count ?? 0);
        $volume = (string) ($recentTransactions->total_volume ?? '0.0000');

        $isVolumeExceeded = bccomp(bcadd($volume, $amount, 4), self::HOURLY_LIMIT_THB, 4) > 0;
        $isCountExceeded = ($count + 1) > self::HOURLY_COUNT_LIMIT;

        if ($isVolumeExceeded || $isCountExceeded) {
            return [
                'triggered' => true,
                'rule_id' => 'VELOCITY_HOURLY_EXCEEDED',
                'risk_points' => 45,
                'reason' => 'User exceeded hourly transaction frequency or volume limits.',
            ];
        }

        return ['triggered' => false, 'rule_id' => 'VELOCITY_HOURLY_EXCEEDED', 'risk_points' => 0];
    }
}
