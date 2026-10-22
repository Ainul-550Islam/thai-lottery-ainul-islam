<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\WalletStatus;
use App\Enums\WalletType;
use App\Models\Draw;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService as CanonicalWalletService;
use App\Services\Order\OrderPlacementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class StressTestOrderPlacementCommand extends Command
{
    protected $signature = 'benchmark:order-placement {concurrency=100 : Number of simulated orders} {users=10 : Distinct users}';

    protected $description = 'Simulates heavy concurrent order placement against Redis locks and double-entry ledger.';

    public function handle(OrderPlacementService $orderService, CanonicalWalletService $wallets): int
    {
        $concurrency = (int) $this->argument('concurrency');
        $userCount = (int) $this->argument('users');

        $this->info("Initializing Concurrency Benchmark: {$concurrency} orders across {$userCount} users...");

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

        $userIds = [];
        for ($i = 1; $i <= $userCount; $i++) {
            $user = User::factory()->create();
            $uId = (string) $user->getKey();
            $userIds[] = $uId;

            DB::transaction(static function () use ($user, $wallets): void {
                $wallet = Wallet::create([
                    'user_id' => $user->getKey(),
                    'type' => WalletType::Main,
                    'currency' => Currency::THB,
                ]);
                $wallet->forceFill(['status' => WalletStatus::Active])->save();
                $wallets->applyCredit(
                    $wallet,
                    Money::of('100000.00', Currency::THB),
                    FinancialTransactionType::Deposit,
                );
            });
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
                    drawId: (string) $draw->id,
                    ticketNumber: $ticketNumber,
                    betType: 'DIRECT_6D',
                    stakeAmount: '100.0000',
                    idempotencyKey: 'BENCH-'.Str::uuid()->toString()
                );
                $successCount++;
            } catch (Throwable) {
                $failedCount++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $elapsed = max(0.001, microtime(true) - $startTime);
        $throughput = round($concurrency / $elapsed, 2);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Orders Attempted', (string) $concurrency],
                ['Successful Orders', (string) $successCount],
                ['Rejected Orders', (string) $failedCount],
                ['Throughput (orders/sec)', (string) $throughput],
            ]
        );

        return self::SUCCESS;
    }
}
