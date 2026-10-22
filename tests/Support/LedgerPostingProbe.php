<?php

declare(strict_types=1);

use App\Exceptions\FinancialException;
use App\Models\FinancialTransaction;
use App\Services\Finance\LedgerPostingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

if (getenv('FINANCIAL_LEDGER_PROBE') !== '1' || getenv('APP_ENV') !== 'testing') {
    fwrite(STDERR, 'Only the explicit disposable financial test harness may run this probe.');
    exit(2);
}
$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
try {
    $file = realpath($argv[1] ?? '');
    $base = realpath(storage_path('framework/testing/ledger-current'));
    if (! $file || ! $base || ! str_starts_with($file, $base.DIRECTORY_SEPARATOR)
        || ! app()->environment('testing') || DB::connection()->getDriverName() !== 'mysql'
        || DB::connection()->getDatabaseName() !== 'thai_lottery_test'
        || ! is_string($socket = DB::connection()->getConfig('unix_socket')) || ! str_starts_with($socket, '/') || filetype($socket) !== 'socket') {
        throw new RuntimeException('Unacknowledged ledger probe context.');
    }
    $command = json_decode(file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
    $snapshotMode = null;
    if (str_contains((string) DB::selectOne('SELECT VERSION() AS v')->v, 'MariaDB')) {
        // Exercise MySQL-style RR current reads, matching the retained race probes.
        // MariaDB snapshot-isolation ON instead raises 1020 on changed old rows.
        $snapshotMode = (int) DB::selectOne('SELECT @@SESSION.innodb_snapshot_isolation AS v')->v;
        DB::statement('SET SESSION innodb_snapshot_isolation = OFF');
    }
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    DB::statement('SET SESSION innodb_lock_wait_timeout = 15');
    DB::beginTransaction();
    $tx = FinancialTransaction::query()->findOrFail($command['transaction_id']);
    $snapshot = ['status' => $tx->status->value, 'entries' => DB::table('ledger_entries')->where('financial_transaction_id', $tx->id)->count()];
    $connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
    file_put_contents($file.'.ready.tmp', json_encode(['connection_id' => $connection, 'snapshot' => $snapshot], JSON_THROW_ON_ERROR));
    rename($file.'.ready.tmp', $file.'.ready');
    $deadline = microtime(true) + 20;
    while (! is_file(dirname($file).'/go') && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (! is_file(dirname($file).'/go')) {
        throw new RuntimeException('Ledger barrier was never released.');
    }
    $outcome = 'posted';
    $code = null;
    try {
        $entries = app(LedgerPostingService::class)->post($tx, $command['entries']);
        $ids = array_map(fn ($row) => $row->id, $entries);
    } catch (FinancialException $e) {
        $outcome = 'refused';
        $code = $e->errorCode();
        $ids = [];
    }
    DB::commit();
    if ($snapshotMode !== null) {
        DB::statement('SET SESSION innodb_snapshot_isolation = '.($snapshotMode === 1 ? 'ON' : 'OFF'));
    }
    echo json_encode(['outcome' => $outcome, 'code' => $code, 'entry_ids' => $ids, 'snapshot' => $snapshot,
        'snapshot_mode_restored' => $snapshotMode === null || (int) DB::selectOne('SELECT @@SESSION.innodb_snapshot_isolation AS v')->v === $snapshotMode,
        'connection_id' => $connection, 'level' => DB::transactionLevel(), 'physical' => DB::connection()->getPdo()->inTransaction()], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack(0);
    }
    fwrite(STDERR, get_class($e).': '.$e->getMessage());
    exit(1);
}
