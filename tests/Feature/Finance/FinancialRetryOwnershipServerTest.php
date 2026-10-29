<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\Currency;
use App\Models\FinancialTransaction;
use App\Services\Finance\LedgerBalanceValidator;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\InnoDbLockWitness;
use Tests\Support\RunsServerFinancialRaces;

final class FinancialRetryOwnershipServerTest extends FinancialMasterTestCase
{
    use RunsServerFinancialRaces;

    public static function profiles(): array
    {
        return [['deadlock', 'root'], ['deadlock', 'ambient'], ['timeout', 'root'], ['timeout', 'ambient']];
    }

    #[DataProvider('profiles')]
    public function test_real_engine_abort_and_statement_timeout_respect_root_retry_ownership(string $fault, string $mode): void
    {
        $this->requireServerRace();
        $wallets = [$this->createPlayer('0.00', Currency::THB)['wallet'], $this->createPlayer('0.00', Currency::BDT)['wallet']];
        foreach ($wallets as $wallet) {
            $this->creditFixture($wallet, '500.00', 'retry-race-start');
        }
        $blocker = $fault === 'timeout' ? $this->createPlayer('0.00')['wallet'] : null;
        $directory = storage_path('framework/testing/financial-retries/'.bin2hex(random_bytes(12)));
        mkdir($directory, 0700, true);
        $processes = [];
        $files = [];
        $ready = [];
        $waiting = null;
        try {
            if ($blocker !== null) {
                DB::beginTransaction();
                DB::table('wallets')->where('id', $blocker->id)->lockForUpdate()->first();
            }
            foreach ($wallets as $index => $wallet) {
                $file = $directory.'/child-'.$index.'.json';
                file_put_contents($file, json_encode(['mode' => $mode, 'fault' => $fault, 'wallet_id' => $wallet->id,
                    'other_wallet_id' => $blocker?->id ?? $wallets[1 - $index]->id, 'token' => basename($directory)], JSON_THROW_ON_ERROR));
                $files[] = $file;
                $process = new Process([PHP_BINARY, base_path('tests/Support/FinancialRetryProbe.php'), $file], base_path(), [
                    'FINANCIAL_RETRY_PROBE' => '1', 'APP_ENV' => 'testing', 'DB_CONNECTION' => config('database.default'),
                    'DB_DATABASE' => 'thai_lottery_test', 'DB_URL' => '', 'DB_SOCKET' => (string) DB::connection()->getConfig('unix_socket'),
                    'DB_USERNAME' => (string) DB::connection()->getConfig('username'), 'DB_PASSWORD' => (string) DB::connection()->getConfig('password'),
                    'APP_KEY' => config('app.key'), 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'database',
                ]);
                $process->setTimeout(45);
                $process->start();
                $processes[] = $process;
            }
            $waitFor = function (array $paths) use ($processes): void {
                $deadline = microtime(true) + 20;
                while (count(array_filter($paths, 'is_file')) !== count($paths) && microtime(true) < $deadline) {
                    foreach ($processes as $process) {
                        if (! $process->isRunning()) {
                            $this->fail('Retry child exited early: '.$process->getErrorOutput().' / '.$process->getOutput());
                        }
                    }
                    usleep(10000);
                }
                foreach ($paths as $path) {
                    $this->assertFileExists($path);
                }
            };
            $waitFor(array_map(fn ($f) => $f.'.ready', $files));
            foreach ($files as $file) {
                $ready[] = json_decode(file_get_contents($file.'.ready'), true, 16, JSON_THROW_ON_ERROR);
            }
            $this->assertSame(['500.00', '500.00'], array_column($ready, 'balance_before'));
            $this->assertSame(['510.00', '510.00'], array_column($ready, 'balance_staged'));
            $this->assertSame([true, true], array_column($ready, 'physical'));
            $this->assertSame(array_fill(0, 2, $mode === 'root' ? 1 : 2), array_column($ready, 'level'));
            $ids = array_column($ready, 'connection_id');
            $this->assertCount(2, array_unique($ids));
            file_put_contents($directory.'/go', 'go');
            if ($blocker !== null) {
                $parent = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
                $deadline = microtime(true) + 3;
                do {
                    $waiting = InnoDbLockWitness::waiting(DB::connection(), $ids, $parent);
                    if ($waiting !== 2) {
                        usleep(150000);
                    }
                } while ($waiting !== 2 && microtime(true) < $deadline);
                $this->assertSame(2, $waiting);
                $waitFor(array_map(fn ($f) => $f.'.timeout', $files));
                DB::rollBack();
                file_put_contents($directory.'/retry-go', 'go');
            }
            $rows = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $this->assertSame('', $process->getErrorOutput());
                $rows[] = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
            }
            $errors = array_merge(...array_column($rows, 'faults'));
            $this->assertCount($fault === 'deadlock' ? 1 : 2, $errors);
            $this->assertSame(array_fill(0, count($errors), $fault === 'deadlock' ? 1213 : 1205), array_column($errors, 'code'));
            if ($fault === 'timeout') {
                $this->assertSame([true, true], array_column($errors, 'physical'));
            }
            $this->assertSame([0, 0], array_column($rows, 'level'));
            $this->assertSame([false, false], array_column($rows, 'physical'));
            $wins = $mode === 'root' ? 2 : ($fault === 'deadlock' ? 1 : 0);
            $this->assertSame($mode === 'root' ? ($fault === 'deadlock' ? 3 : 4) : 2, array_sum(array_column($rows, 'attempts')));
            $this->assertSame($wins, count(array_filter($rows, fn ($row) => $row['outcome'] === 'completed')));
            $this->assertSame(2 + $wins, FinancialTransaction::query()->count());
            $this->assertSame(4 + 2 * $wins, DB::table('ledger_entries')->count());
            foreach ($rows as $index => $row) {
                $this->assertSame($row['outcome'] === 'completed' ? '510.00' : '500.00', $wallets[$index]->fresh()->balance);
                $this->assertSame($wallets[$index]->currency->value, $row['currency']);
            }
            foreach (FinancialTransaction::all() as $row) {
                app(LedgerBalanceValidator::class)->assertTransactionBalanced($row);
            }
            if ($mode === 'ambient' && $fault === 'timeout') {
                $this->assertSame(['committable caller marker', 'committable caller marker'], array_column($rows, 'caller_marker'));
            }
            $witness = getenv('FINANCIAL_RETRY_WITNESS');
            if (is_string($witness) && str_starts_with($witness, '/tmp/financial-retry-')) {
                file_put_contents($witness, json_encode(['fault' => $fault, 'mode' => $mode, 'ready' => $ready,
                    'actual_driver_faults' => $errors, 'parent_two_child_waits' => $waiting, 'results' => $rows, 'final_balances' => array_map(fn ($w) => ['currency' => $w->currency->value, 'balance' => $w->fresh()->balance], $wallets),
                    'financial_transactions' => FinancialTransaction::query()->count(), 'ledger_entries' => DB::table('ledger_entries')->count()], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
            }
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack(0);
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
