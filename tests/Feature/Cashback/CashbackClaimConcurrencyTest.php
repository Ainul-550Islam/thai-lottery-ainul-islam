<?php

declare(strict_types=1);

namespace Tests\Feature\Cashback;

use App\Enums\CashbackAccrualStatus;
use App\Enums\Currency;
use App\Enums\WalletType;
use App\Models\CashbackAccrual;
use App\Models\CashbackPolicy;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Services\Cashback\CashbackClaimService;
use App\Services\Finance\WalletService;
use Database\Seeders\CashbackPolicySeeder;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class CashbackClaimConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_parallel_and_replay_claims_credit_wallet_once_with_zero_double_pay(): void
    {
        $this->seed([
            LedgerAccountSeeder::class,
            CashbackPolicySeeder::class,
        ]);

        $member = User::factory()->create();
        app(WalletService::class)->getOrCreateWallet($member, Currency::THB, WalletType::Primary);
        $policy = CashbackPolicy::query()->firstOrFail();

        CashbackAccrual::query()->create([
            'member_id' => $member->getKey(),
            'policy_id' => $policy->getKey(),
            'period_start' => now()->subDay()->startOfDay(),
            'period_end' => now()->subDay()->endOfDay(),
            'basis_amount' => '2500.00',
            'accrual_amount' => '25.00',
            'status' => CashbackAccrualStatus::Accrued,
            'expires_at' => now()->addDays(30),
        ]);

        $claimService = app(CashbackClaimService::class);

        $first = $claimService->claim($member, 'concurrent-same-key-0001');
        $replay = $claimService->claim($member, 'concurrent-same-key-0001');

        $this->assertFalse($first['replayed']);
        $this->assertTrue($replay['replayed']);
        $this->assertSame($first['transaction_id'], $replay['transaction_id']);

        $secondKeyRefused = false;
        try {
            $claimService->claim($member, 'concurrent-second-key-0002');
        } catch (\Throwable) {
            $secondKeyRefused = true;
        }

        $this->assertTrue($secondKeyRefused);
        $this->assertSame(1, FinancialTransaction::query()->where('reference_type', CashbackAccrual::class)->count());

        $mainWallet = app(WalletService::class)->getOrCreateWallet($member, Currency::THB, WalletType::Primary);
        $this->assertSame('25.00', bcadd((string) $mainWallet->fresh()?->balance, '0.00', 2));
    }

    public function test_two_simultaneous_os_processes_claiming_cashback_credit_wallet_once(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // Two PHP processes opening one SQLite file at the same instant collide on
            // Windows file locking at connect time ("database is locked") before the
            // claim is even reached, so a failure here says nothing about the claim.
            // The test runs on Linux CI; SQLite is not row-lock proof in any case, and
            // the real money-race proof is the InnoDB server race.
            $this->markTestSkipped('Two-process SQLite race is unreliable on Windows file locking; run it on Linux CI.');
        }

        $dbDir = sys_get_temp_dir().'/batch1_cb_race_'.bin2hex(random_bytes(8));
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
$app->make(Database\Seeders\CashbackPolicySeeder::class)->run();
$m = App\Models\User::factory()->create();
$app->make(App\Services\Finance\WalletService::class)->getOrCreateWallet($m, App\Enums\Currency::THB, App\Enums\WalletType::Primary);
$policy = App\Models\CashbackPolicy::query()->firstOrFail();
App\Models\CashbackAccrual::query()->create([
    'member_id' => $m->getKey(),
    'policy_id' => $policy->getKey(),
    'period_start' => now()->subDay()->startOfDay(),
    'period_end' => now()->subDay()->endOfDay(),
    'basis_amount' => '2500.00',
    'accrual_amount' => '25.00',
    'status' => App\Enums\CashbackAccrualStatus::Accrued,
    'expires_at' => now()->addDays(30),
]);
echo $m->getKey();
PHP;
            $setup = new Process([PHP_BINARY, '-r', $setupCode], base_path(), $env, null, 30);
            $setup->mustRun();
            $memberId = (int) trim($setup->getOutput());

            $workerCode = static fn (int $id, string $key): string => sprintf(<<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['real_money.enabled' => true]);
// Two workers opening the same SQLite file at the same instant can collide on the
// connect-time journal PRAGMA before any busy timeout exists. That is a harness
// artefact, not the claim path, so only the connect is retried; the claim itself
// below is attempted exactly once.
for ($attempt = 0; $attempt < 100; $attempt++) {
    try {
        Illuminate\Support\Facades\DB::statement('PRAGMA busy_timeout = 15000;');
        break;
    } catch (Throwable $e) {
        Illuminate\Support\Facades\DB::purge();
        usleep(50000);
    }
}
$m = App\Models\User::query()->findOrFail(%d);
try {
    $res = $app->make(App\Services\Cashback\CashbackClaimService::class)->claim($m, '%s');
    echo 'OK:'.$res['claimed_amount'];
} catch (Throwable $e) {
    echo 'ERR:'.$e->getMessage();
}
PHP, $id, $key);

            $p1 = new Process([PHP_BINARY, '-r', $workerCode($memberId, 'proc-race-key-alpha-0001')], base_path(), $env, null, 30);
            $p2 = new Process([PHP_BINARY, '-r', $workerCode($memberId, 'proc-race-key-bravo-0002')], base_path(), $env, null, 30);

            $p1->start();
            $p2->start();
            $p1->wait();
            $p2->wait();

            $outputs = [trim($p1->getOutput()), trim($p2->getOutput())];
            $okCount = count(array_filter($outputs, static fn (string $o): bool => str_starts_with($o, 'OK:25.00')));
            $this->assertSame(1, $okCount, 'Expected exactly 1 worker to claim 25.00, got: '.implode(' | ', $outputs));

            $pdo = new \PDO('sqlite:'.$dbPath);
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM financial_transactions WHERE user_id = ? AND reference_type = ?');
            $stmt->execute([$memberId, CashbackAccrual::class]);
            $txCount = (int) $stmt->fetchColumn();
            $rawBalance = (string) $pdo->query("SELECT balance FROM wallets WHERE user_id = {$memberId} AND type = 'primary'")->fetchColumn();
            $claimedRows = (int) $pdo->query("SELECT COUNT(*) FROM cashback_accruals WHERE member_id = {$memberId} AND status = 'claimed'")->fetchColumn();
            unset($stmt, $pdo);

            $this->assertSame(1, $txCount);
            $this->assertSame(1, $claimedRows);
            $this->assertSame('25.00', bcadd($rawBalance, '0.00', 2));
        } finally {
            foreach (glob($dbDir.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dbDir);
        }
    }
}
