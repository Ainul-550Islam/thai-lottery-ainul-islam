<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Models\LedgerTransaction;
use App\Models\LotteryEntry;
use App\Models\Wallet;
use Illuminate\Support\Facades\Redis;

/**
 * Class MetricsCollectorService
 *
 * Real-time Prometheus metrics exporter service for high-throughput FinTech telemetry.
 */
class MetricsCollectorService
{
    /**
     * Generate Prometheus-compliant metrics payload.
     */
    public function generatePrometheusMetrics(): string
    {
        $metrics = [];

        // 1. Wallets Aggregate Total
        $totalUserBalance = (string) (Wallet::where('type', 'main')->sum('balance') ?? '0.0000');
        $totalLockedBalance = (string) (Wallet::where('type', 'main')->sum('locked_balance') ?? '0.0000');
        $activeWalletsCount = Wallet::where('status', 'active')->count();

        $metrics[] = '# HELP fintech_wallet_total_balance_thb Total user balance in system liabilities';
        $metrics[] = '# TYPE fintech_wallet_total_balance_thb gauge';
        $metrics[] = "fintech_wallet_total_balance_thb {$totalUserBalance}";

        $metrics[] = '# HELP fintech_wallet_locked_balance_thb Total locked/escrow balance';
        $metrics[] = '# TYPE fintech_wallet_locked_balance_thb gauge';
        $metrics[] = "fintech_wallet_locked_balance_thb {$totalLockedBalance}";

        $metrics[] = '# HELP fintech_active_wallets_count Active wallet count';
        $metrics[] = '# TYPE fintech_active_wallets_count gauge';
        $metrics[] = "fintech_active_wallets_count {$activeWalletsCount}";

        // 2. Ledger Transactions Count
        $totalPostedTransactions = LedgerTransaction::where('status', 'POSTED')->count();
        $metrics[] = '# HELP fintech_ledger_transactions_total Cumulative immutable double-entry postings';
        $metrics[] = '# TYPE fintech_ledger_transactions_total counter';
        $metrics[] = "fintech_ledger_transactions_total {$totalPostedTransactions}";

        // 3. Entries Placed Today
        $entriesPlacedToday = LotteryEntry::whereDate('placed_at', today())->count();
        $metrics[] = '# HELP fintech_lottery_entries_today_total Entries placed today';
        $metrics[] = '# TYPE fintech_lottery_entries_today_total counter';
        $metrics[] = "fintech_lottery_entries_today_total {$entriesPlacedToday}";

        // 4. Redis Queue Depth
        try {
            $payoutsQueueLen = (int) Redis::llen('queues:payouts');
            $settlementQueueLen = (int) Redis::llen('queues:settlement');
            $commissionsQueueLen = (int) Redis::llen('queues:commissions');

            $metrics[] = '# HELP fintech_queue_depth Pending jobs in Redis queues';
            $metrics[] = '# TYPE fintech_queue_depth gauge';
            $metrics[] = "fintech_queue_depth{queue=\"payouts\"} {$payoutsQueueLen}";
            $metrics[] = "fintech_queue_depth{queue=\"settlement\"} {$settlementQueueLen}";
            $metrics[] = "fintech_queue_depth{queue=\"commissions\"} {$commissionsQueueLen}";
        } catch (\Throwable) {
            // Redis fallback
        }

        return implode("\n", $metrics)."\n";
    }
}
