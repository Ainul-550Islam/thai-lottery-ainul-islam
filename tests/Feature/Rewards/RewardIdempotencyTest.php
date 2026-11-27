<?php

declare(strict_types=1);

namespace Tests\Feature\Rewards;

use App\Enums\Currency;
use App\Enums\WalletType;
use App\Models\FinancialTransaction;
use App\Models\LoyaltyPointLedger;
use App\Models\RewardCatalogItem;
use App\Models\RewardClaim;
use App\Models\User;
use App\Services\Finance\WalletService;
use App\Services\Loyalty\LoyaltyPointsService;
use Database\Seeders\LedgerAccountSeeder;
use Database\Seeders\RewardCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class RewardIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_idempotency_key_returns_original_claim_with_single_debit_and_fulfillment(): void
    {
        $this->seed([
            LedgerAccountSeeder::class,
            RewardCatalogSeeder::class,
        ]);

        $member = User::factory()->create();
        app(WalletService::class)->getOrCreateWallet((int) $member->getKey(), Currency::THB, WalletType::Bonus);
        LoyaltyPointLedger::query()->create([
            'user_id' => $member->getKey(),
            'entry_type' => 'earn',
            'points' => 1500,
            'balance_after' => 1500,
            'source_type' => 'test_seed',
            'source_id' => 'seed-'.$member->getKey(),
            'idempotency_key' => 'seed-pts-'.$member->getKey().'-'.uniqid(),
            'description' => 'Seed points',
        ]);

        $item = RewardCatalogItem::query()->where('code', 'bonus_cash_100')->firstOrFail();

        $first = $this->actingAs($member, 'sanctum')->postJson("/api/v1/me/rewards/{$item->id}/claim", [
            'idempotency_key' => 'idem-reward-same-key-0001',
        ]);
        $second = $this->actingAs($member, 'sanctum')->postJson("/api/v1/me/rewards/{$item->id}/claim", [
            'idempotency_key' => 'idem-reward-same-key-0001',
        ]);

        $first->assertCreated()->assertJsonPath('data.replayed', false);
        $second->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame($first->json('data.claim.id'), $second->json('data.claim.id'));

        $this->assertSame(1, RewardClaim::query()->count());
        $this->assertSame(1, FinancialTransaction::query()->where('reference_type', RewardClaim::class)->count());
        $this->assertSame(1, LoyaltyPointLedger::query()->where('points', '<', 0)->count());
        $this->assertSame(500, app(LoyaltyPointsService::class)->currentBalance($member->fresh()));

        // Distinct key when only 500 points remain must fail cleanly without negative balance
        $third = $this->actingAs($member, 'sanctum')->postJson("/api/v1/me/rewards/{$item->id}/claim", [
            'idempotency_key' => 'idem-reward-distinct-key-0002',
        ]);
        $third->assertStatus(422);
        $this->assertSame(500, app(LoyaltyPointsService::class)->currentBalance($member->fresh()));

        // Uniqueness is scoped per member+item: another member with sufficient points can use the same key string independently
        $otherMember = User::factory()->create();
        app(WalletService::class)->getOrCreateWallet((int) $otherMember->getKey(), Currency::THB, WalletType::Bonus);
        LoyaltyPointLedger::query()->create([
            'user_id' => $otherMember->getKey(),
            'entry_type' => 'earn',
            'points' => 1500,
            'balance_after' => 1500,
            'source_type' => 'test_seed',
            'source_id' => 'seed-other-'.$otherMember->getKey(),
            'idempotency_key' => 'seed-pts-other-'.$otherMember->getKey(),
            'description' => 'Seed points for second member',
        ]);
        $this->actingAs($otherMember, 'sanctum')->postJson("/api/v1/me/rewards/{$item->id}/claim", [
            'idempotency_key' => 'idem-reward-same-key-0001',
        ])->assertCreated()->assertJsonPath('data.replayed', false);
    }

    public function test_two_simultaneous_os_processes_redeeming_points_never_go_negative_or_double_fulfill(): void
    {
        $dbDir = sys_get_temp_dir().'/batch1_rw_race_'.bin2hex(random_bytes(8));
        mkdir($dbDir, 0700, true);
        $dbPath = $dbDir.'/thai_lottery_test';
        copy(database_path('thai_lottery_test'), $dbPath);

        try {
            $env = [
                'APP_ENV' => 'testing',
                'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => $dbPath,
                'DB_BUSY_TIMEOUT' => '15000',
                // The workers must open this database in the WAL mode the setup step
                // put it in. Without this they inherit phpunit.xml's
                // DB_JOURNAL_MODE=MEMORY, so Laravel's connector runs
                // `pragma journal_mode = MEMORY` on connect; leaving WAL needs an
                // exclusive lock that SQLite does NOT wait for (busy_timeout is
                // ignored), so whichever worker connects second dies with
                // "database is locked" before reaching the code under test.
                'DB_JOURNAL_MODE' => 'WAL',
                'CACHE_STORE' => 'array',
                'QUEUE_CONNECTION' => 'sync',
            ];

            $setupCode = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['real_money.enabled' => true]);
Illuminate\Support\Facades\DB::statement('PRAGMA journal_mode = WAL;');
Illuminate\Support\Facades\DB::statement('PRAGMA busy_timeout = 15000;');
$app->make(Database\Seeders\LedgerAccountSeeder::class)->run();
$app->make(Database\Seeders\RewardCatalogSeeder::class)->run();
$m = App\Models\User::factory()->create();
$app->make(App\Services\Finance\WalletService::class)->getOrCreateWallet((int) $m->getKey(), App\Enums\Currency::THB, App\Enums\WalletType::Bonus);
App\Models\LoyaltyPointLedger::query()->create([
    'user_id' => $m->getKey(),
    'entry_type' => 'earn',
    'points' => 1000,
    'balance_after' => 1000,
    'source_type' => 'test_seed',
    'source_id' => 'seed-race-'.$m->getKey(),
    'idempotency_key' => 'seed-race-pts-'.$m->getKey(),
    'description' => 'Seed race points',
]);
$item = App\Models\RewardCatalogItem::query()->where('code', 'bonus_cash_100')->firstOrFail();
echo $m->getKey().':'.$item->getKey();
PHP;
            $setup = new Process([PHP_BINARY, '-r', $setupCode], base_path(), $env, null, 30);
            $setup->mustRun();
            [$memberIdStr, $itemIdStr] = explode(':', trim($setup->getOutput()));
            $memberId = (int) $memberIdStr;
            $itemId = (int) $itemIdStr;

            $workerCode = static fn (int $mId, int $iId, string $key): string => sprintf(<<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['real_money.enabled' => true]);
Illuminate\Support\Facades\DB::statement('PRAGMA busy_timeout = 15000;');
$m = App\Models\User::query()->findOrFail(%d);
try {
    $res = $app->make(App\Services\Rewards\RewardRedemptionService::class)->redeem($m, %d, '%s');
    echo 'OK:'.$res['claim']->id;
} catch (Throwable $e) {
    echo 'ERR:'.$e->getMessage();
}
PHP, $mId, $iId, $key);

            $p1 = new Process([PHP_BINARY, '-r', $workerCode($memberId, $itemId, 'rw-race-alpha-0001')], base_path(), $env, null, 30);
            $p2 = new Process([PHP_BINARY, '-r', $workerCode($memberId, $itemId, 'rw-race-bravo-0002')], base_path(), $env, null, 30);

            $p1->start();
            $p2->start();
            $p1->wait();
            $p2->wait();

            $outputs = [trim($p1->getOutput()), trim($p2->getOutput())];
            $okCount = count(array_filter($outputs, static fn (string $o): bool => str_starts_with($o, 'OK:')));
            $this->assertSame(1, $okCount, 'Expected exactly 1 worker to redeem 1000 pts, got: '.implode(' | ', $outputs));

            $pdo = new \PDO('sqlite:'.$dbPath);
            $points = (int) $pdo->query("SELECT COALESCE(SUM(points), 0) FROM loyalty_point_ledger WHERE user_id = {$memberId}")->fetchColumn();
            $claims = (int) $pdo->query("SELECT COUNT(*) FROM reward_claims WHERE member_id = {$memberId}")->fetchColumn();
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM financial_transactions WHERE user_id = ? AND reference_type = ?');
            $stmt->execute([$memberId, RewardClaim::class]);
            $txCount = (int) $stmt->fetchColumn();
            $rawBonusBalance = (string) $pdo->query("SELECT balance FROM wallets WHERE user_id = {$memberId} AND type = 'bonus'")->fetchColumn();
            unset($stmt, $pdo);

            $this->assertSame(0, $points);
            $this->assertSame(1, $claims);
            $this->assertSame(1, $txCount);
            $this->assertSame('100.00', bcadd($rawBonusBalance, '0.00', 2));
        } finally {
            foreach (glob($dbDir.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dbDir);
        }
    }
}
