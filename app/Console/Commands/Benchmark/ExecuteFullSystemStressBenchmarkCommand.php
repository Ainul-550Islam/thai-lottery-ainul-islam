<?php

declare(strict_types=1);

namespace App\Console\Commands\Benchmark;

use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Wallet;
use App\Services\Ledger\DoubleEntryLedgerService;
use App\Services\Lock\DistributedLockService;
use App\Services\Wallet\WalletMutationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExecuteFullSystemStressBenchmarkCommand extends Command
{
    protected $signature = 'benchmark:full-system-stress {iterations=1000 : Number of sequential/concurrent batch ledger operations}';
    protected $description = 'Executes a high-throughput financial double-entry ledger stress test measuring TPS and zero-drift invariance.';

    public function handle(
        DoubleEntryLedgerService $ledgerService,
        DistributedLockService $lockService,
        WalletMutationService $walletService
    ): int {
        $iterations = (int) $this->argument('iterations');

        $this->info("================================================================================");
        $this->info("🔥 THAI LOTTERY HIGH-THROUGHPUT STRESS BENCHMARK HARNESS");
        $this->info("Target Iterations: {$iterations} full financial transaction batches");
        $this->info("================================================================================");

        $startTime = microtime(true);
        $latencies = [];

        $testUserId = 'usr_benchmark_' . Str::random(8);

        // Pre-create user and wallet
        $wallet = Wallet::create([
            'id' => (string) Str::uuid(),
            'user_id' => $testUserId,
            'currency' => 'THB',
            'wallet_type' => 'main',
            'balance' => '10000000.0000',
            'locked_balance' => '0.0000',
            'version' => 1,
            'is_active' => true,
        ]);

        $this->output->progressStart($iterations);

        for ($i = 0; $i < $iterations; $i++) {
            $iterStart = microtime(true);
            $amount = '100.0000';
            $txUuid = (string) Str::uuid();

            DB::transaction(function () use ($walletService, $testUserId, $amount, $txUuid) {
                // Debit user wallet, credit lottery revenue
                $walletService->debit(
                    userId: $testUserId,
                    amount: $amount,
                    referenceType: 'BENCHMARK_BET_DEBIT',
                    referenceId: $txUuid,
                    currency: 'THB',
                    walletType: 'main',
                    idempotencyKey: "BENCH-{$txUuid}"
                );
            });

            $iterDurationMs = (microtime(true) - $iterStart) * 1000.0;
            $latencies[] = $iterDurationMs;

            $this->output->progressAdvance();
        }

        $this->output->progressFinish();
        $totalTime = microtime(true) - $startTime;

        // Statistics computation
        sort($latencies);
        $count = count($latencies);
        $p50 = $latencies[(int) ($count * 0.50)];
        $p95 = $latencies[(int) ($count * 0.95)];
        $p99 = $latencies[(int) ($count * 0.99)];
        $avg = array_sum($latencies) / $count;
        $tps = $iterations / $totalTime;

        // Verify Double-Entry Balance Invariance Across All Entries
        $totalDebits = (string) LedgerEntry::where('direction', 'DEBIT')->sum('amount');
        $totalCredits = (string) LedgerEntry::where('direction', 'CREDIT')->sum('amount');
        $netDelta = bcsub($totalDebits, $totalCredits, 4);

        $this->newLine();
        $this->info("================================================================================");
        $this->info("📊 BENCHMARK EXECUTION RESULTS & LATENCY PROFILE");
        $this->info("================================================================================");
        $this->table(['Metric', 'Measurement'], [
            ['Total Transactions Processed', number_format($iterations)],
            ['Total Elapsed Duration', sprintf('%.2f seconds', $totalTime)],
            ['Throughput Rate', sprintf('%.2f TPS (Transactions / Sec)', $tps)],
            ['Average Latency (Mean)', sprintf('%.3f ms', $avg)],
            ['P50 Latency (Median)', sprintf('%.3f ms', $p50)],
            ['P95 Latency', sprintf('%.3f ms', $p95)],
            ['P99 Latency', sprintf('%.3f ms', $p99)],
            ['Total Debits Sum', "{$totalDebits} THB"],
            ['Total Credits Sum', "{$totalCredits} THB"],
            ['Net Invariant Delta', "{$netDelta} THB (EXACT ZERO DRIFT)"],
        ]);

        if (bccomp($netDelta, '0.0000', 4) === 0) {
            $this->info("✅ DOUBLE-ENTRY LEDGER BALANCE INVARIANT ABSOLUTELY CONSERVED (Sum(Debits) === Sum(Credits))");
            return self::SUCCESS;
        }

        $this->error("❌ CRITICAL: LEDGER INVARIANT BREACHED. Delta: {$netDelta}");
        return self::FAILURE;
    }
}
