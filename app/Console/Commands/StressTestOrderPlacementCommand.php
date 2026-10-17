<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Draw;
use App\Models\Wallet;
use App\Services\Order\OrderPlacementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class StressTestOrderPlacementCommand extends Command
{
    protected $signature = 'benchmark:order-placement {concurrency=100 : Number of simulated orders} {users=10 : Distinct users}';
    protected $description = 'Simulates heavy concurrent order placement against Redis locks and double-entry ledger.';

    public function handle(OrderPlacementService $orderService): int
    {
        $concurrency = (int) $this->argument('concurrency');
        $userCount = (int) $this->argument('users');

        $this->info("Initializing Concurrency Benchmark: {$concurrency} orders across {$userCount} users...");

        // Setup Draw
        $draw = Draw::firstOrCreate(
            ['draw_code' => 'BENCHMARK-DRAW-LIVE'],
            [
                'id' => Str::uuid()->toString(),
                'game_type' => 'THAI_6D',
                'draw_date' => now()->addDay(),
                'betting_starts_at' => now()->subHour(),
                'betting_ends_at' => now()->addHours(12),
                'status' => 'OPEN',
            ]
        );

        // Setup Users & Wallets
        $userIds = [];
        for ($i = 1; $i <= $userCount; $i++) {
            $uId = Str::uuid()->toString();
            $userIds[] = $uId;
            Wallet::create([
                'id' => Str::uuid()->toString(),
                'user_id' => $uId,
                'type' => 'main',
                'currency' => 'THB',
                'status' => 'active',
            ]);
            DB::table('wallets')->where('user_id', $uId)->update(['balance' => '100000.0000']);
        }

        $startTime = microtime(true);
        $successCount = 0;
        $failedCount = 0;

        $bar = $this->output->createProgressBar($concurrency);
        $bar->start();

        for ($i = 0; $i < $concurrency; $i++) {
            $selectedUser = $userIds[array_rand($userIds)];
            $ticketNumber = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            try {
                $orderService->placeOrder(
                    userId: $selectedUser,
                    drawId: $draw->id,
                    ticketNumber: $ticketNumber,
                    betType: 'DIRECT_6D',
                    stakeAmount: '100.0000',
                    idempotencyKey: 'BENCH-' . Str::uuid()->toString()
                );
                $successCount++;
            } catch (Throwable $e) {
                $failedCount++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        $duration = microtime(true) - $startTime;
        $rps = $duration > 0 ? $concurrency / $duration : 0;

        $this->table(['Metric', 'Result'], [
            ['Total Simulated Orders', (string) $concurrency],
            ['Successful Placements', (string) $successCount],
            ['Failed / Lock Contention', (string) $failedCount],
            ['Total Time Elapsed', round($duration, 3) . ' s'],
            ['Throughput Rate', round($rps, 2) . ' Orders/sec'],
        ]);

        return self::SUCCESS;
    }
}
