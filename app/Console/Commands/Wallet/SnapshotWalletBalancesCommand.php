<?php

declare(strict_types=1);

namespace App\Console\Commands\Wallet;

use App\Services\Wallet\WalletBalanceSnapshotService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * wallet:snapshot-balances [--day=YYYY-MM-DD]
 *
 * A thin operator handle on WalletBalanceSnapshotService. Safe to run any number
 * of times: the service upserts per (member, wallet, day), so a second run changes
 * nothing but the captured_at stamp.
 */
final class SnapshotWalletBalancesCommand extends Command
{
    protected $signature = 'wallet:snapshot-balances
        {--day= : The calendar day to capture (default: today)}
        {--chunk=500 : Members per chunk}';

    protected $description = 'Capture one balance snapshot per wallet per day (idempotent).';

    public function handle(WalletBalanceSnapshotService $snapshots): int
    {
        $dayOption = $this->option('day');
        $day = is_string($dayOption) && $dayOption !== '' ? Carbon::parse($dayOption) : Carbon::now();

        $result = $snapshots->captureAll($day, (int) $this->option('chunk'));

        $this->info(sprintf(
            'Captured %d snapshot rows for %d members on %s.',
            $result['rows'],
            $result['members'],
            $day->toDateString(),
        ));

        return self::SUCCESS;
    }
}
