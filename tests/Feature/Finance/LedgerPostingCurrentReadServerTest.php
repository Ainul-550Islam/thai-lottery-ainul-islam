<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Exceptions\FinancialException;
use App\Services\Finance\LedgerPostingService;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\RunsServerFinancialRaces;

final class LedgerPostingCurrentReadServerTest extends FinancialMasterTestCase
{
    use RunsServerFinancialRaces;

    public static function profiles(): array
    {
        return ['new-winner' => ['new-winner'], 'already-posted' => ['already-posted'], 'terminal' => ['terminal']];
    }

    #[DataProvider('profiles')]
    public function test_two_old_snapshot_posters_wait_on_transaction_root_and_use_current_facts(string $profile): void
    {
        $this->requireServerRace();
        [$tx, $pairs] = LedgerPostingCurrentReadTest::bookFixture();
        $directory = storage_path('framework/testing/ledger-current/'.bin2hex(random_bytes(12)));
        mkdir($directory, 0700, true);
        $processes = [];
        $files = [];
        try {
            foreach ([0, 1] as $i) {
                $file = $directory.'/child-'.$i.'.json';
                file_put_contents($file, json_encode(['transaction_id' => $tx->id, 'entries' => $pairs[$i]], JSON_THROW_ON_ERROR));
                chmod($file, 0600);
                $files[] = $file;
                $process = new Process([PHP_BINARY, base_path('tests/Support/LedgerPostingProbe.php'), $file], base_path(), [
                    'FINANCIAL_LEDGER_PROBE' => '1', 'APP_ENV' => 'testing', 'APP_BASE_PATH' => base_path(),
                    'DB_CONNECTION' => config('database.default'), 'DB_DATABASE' => 'thai_lottery_test', 'DB_URL' => '',
                    'DB_SOCKET' => (string) DB::connection()->getConfig('unix_socket'),
                    'DB_USERNAME' => (string) DB::connection()->getConfig('username'), 'DB_PASSWORD' => (string) DB::connection()->getConfig('password'),
                    'APP_KEY' => config('app.key'), 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'database',
                ]);
                $process->setTimeout(30);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 15;
            while ((! is_file($files[0].'.ready') || ! is_file($files[1].'.ready')) && microtime(true) < $deadline) {
                foreach ($processes as $process) {
                    if (! $process->isRunning()) {
                        $this->fail('Ledger child failed bootstrap: '.$process->getErrorOutput());
                    }
                }
                usleep(10000);
            }
            $ready = [];
            foreach ($files as $file) {
                $this->assertFileExists($file.'.ready');
                $ready[] = json_decode(file_get_contents($file.'.ready'), true, 16, JSON_THROW_ON_ERROR);
            }
            $ids = array_column($ready, 'connection_id');
            $this->assertCount(2, array_unique($ids));
            $this->assertSame(array_fill(0, 2, ['status' => 'pending', 'entries' => 0]), array_column($ready, 'snapshot'));
            $parent = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            $this->assertNotContains($parent, $ids);
            DB::beginTransaction();
            $this->assertNotNull(DB::table('financial_transactions')->where('id', $tx->id)->lockForUpdate()->first());
            if ($profile === 'terminal') {
                DB::table('financial_transactions')->where('id', $tx->id)->update(['status' => 'failed']);
            } elseif ($profile === 'already-posted') {
                app(LedgerPostingService::class)->post($tx, $pairs[2]);
            }
            file_put_contents($directory.'/go', 'go');
            $deadline = microtime(true) + 5;
            $waiting = 0;
            do {
                $waiting = (int) DB::selectOne('SELECT COUNT(DISTINCT t.trx_mysql_thread_id) AS n '
                    .'FROM information_schema.INNODB_LOCK_WAITS w '
                    .'JOIN information_schema.INNODB_TRX t ON t.trx_id = w.requesting_trx_id '
                    .'JOIN information_schema.INNODB_TRX b ON b.trx_id = w.blocking_trx_id '
                    .'JOIN information_schema.INNODB_LOCKS l ON l.lock_id = w.requested_lock_id '
                    .'WHERE t.trx_mysql_thread_id IN (?, ?) AND b.trx_mysql_thread_id = ? '
                    .'AND l.lock_table = ? AND l.lock_index = ?', [...$ids, $parent, '`thai_lottery_test`.`financial_transactions`', 'PRIMARY'])->n;
                if ($waiting !== 2) {
                    usleep(200000);
                }
            } while ($waiting !== 2 && microtime(true) < $deadline);
            DB::commit();
            $this->assertSame(2, $waiting, 'Both real children must wait on the parent transaction PRIMARY row, not merely an account or journal gap.');
            $rows = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $this->assertSame('', $process->getErrorOutput());
                $rows[] = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
            }
            $this->assertSame([true, true], array_column($rows, 'snapshot_mode_restored'));
            $this->assertSame([0, 0], array_column($rows, 'level'));
            $this->assertSame([false, false], array_column($rows, 'physical'));
            $this->assertSame(array_fill(0, 2, ['status' => 'pending', 'entries' => 0]), array_column($rows, 'snapshot'));
            $witness = getenv('FINANCIAL_LEDGER_POSTING_WITNESS');
            if (is_string($witness) && str_starts_with($witness, sys_get_temp_dir().'/financial-ledger-posting-')) {
                file_put_contents($witness, json_encode(['profile' => $profile, 'table' => 'financial_transactions', 'lock_index' => 'PRIMARY',
                    'parent' => $parent, 'children' => $ids, 'waiting_children' => $waiting, 'snapshots' => array_column($rows, 'snapshot'),
                    'outcomes' => array_column($rows, 'outcome'), 'codes' => array_column($rows, 'code')], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
            }
            $outcomes = array_column($rows, 'outcome');
            sort($outcomes);
            $this->assertSame($profile === 'new-winner' ? ['posted', 'refused'] : ['refused', 'refused'], $outcomes);
            foreach ($rows as $row) {
                if ($row['outcome'] === 'refused') {
                    $this->assertSame($profile === 'terminal' ? 'ledger_posting_invalid_transaction_status' : 'ledger_duplicate_posting', $row['code']);
                }
            }
            $this->assertSame($profile === 'terminal' ? 0 : 2, DB::table('ledger_entries')->count());
            $this->assertSame(1, DB::table('financial_transactions')->count());
            $this->assertSame(0, DB::table('wallets')->count());
            $balances = DB::table('ledger_accounts')->whereIn('id', array_merge(...array_map(fn ($pair) => array_column($pair, 'ledger_account_id'), $pairs)))->pluck('current_balance')->map(fn ($b) => bccomp((string) $b, '10.00', 2) === 0)->all();
            $this->assertSame($profile === 'terminal' ? 0 : 2, count(array_filter($balances)));
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

    public function test_current_observer_refuses_an_actual_other_store_without_journal_writes_or_owning_caller_commit(): void
    {
        $this->requireServerRace();
        [$tx, $pairs] = LedgerPostingCurrentReadTest::bookFixture();
        $factory = app('db.factory');
        $wrong = new class(app()) extends ConnectionFactory
        {
            public int $calls = 0;

            public function make(array $config, $name = null)
            {
                $this->calls++;
                if (isset($config['read']) || isset($config['write']) || $config['options'][\PDO::ATTR_PERSISTENT] !== false) {
                    throw new \RuntimeException('Observer must use a nonpersistent effective writer configuration.');
                }
                $config['database'] = 'information_schema';

                return parent::make($config, $name);
            }
        };
        DB::beginTransaction();
        try {
            DB::table('financial_transactions')->where('id', $tx->id)->update(['description' => 'committable caller marker']);
            app()->instance('db.factory', $wrong);
            try {
                app(LedgerPostingService::class)->post($tx, $pairs[0]);
                $this->fail('A current observer on another real store must be refused.');
            } catch (FinancialException $e) {
                $this->assertSame('ledger_posting_current_reader_mismatch', $e->errorCode());
            } finally {
                app()->instance('db.factory', $factory);
            }
            $this->assertSame(1, $wrong->calls);
            $this->assertSame(1, DB::transactionLevel());
            $this->assertTrue(DB::connection()->getPdo()->inTransaction());
            DB::commit();
            $this->assertSame('committable caller marker', $tx->fresh()->description);
            $this->assertSame(0, DB::table('ledger_entries')->count());
            $this->assertSame(0, DB::table('ledger_accounts')->where('current_balance', '<>', 0)->count());
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        } finally {
            app()->instance('db.factory', $factory);
            if (DB::transactionLevel() > 0) {
                DB::rollBack(0);
            }
        }
    }
}
