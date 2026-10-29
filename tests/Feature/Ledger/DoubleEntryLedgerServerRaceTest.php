<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Services\Ledger\DoubleEntryLedgerService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\InnoDbLockWitness;
use Tests\Support\RunsServerFinancialRaces;
use Tests\TestCase;

/** Competing committed writers on an actual InnoDB hash-chain tip. */
final class DoubleEntryLedgerServerRaceTest extends TestCase
{
    use DatabaseTruncation;
    use RunsServerFinancialRaces;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireServerRace();
    }

    public static function raceCases(): array
    {
        return [
            'distinct posting identities' => [false],
            'one shared idempotency identity' => [true],
        ];
    }

    #[DataProvider('raceCases')]
    public function test_standalone_postings_survive_two_real_waiters_without_a_fork_or_partial_journal(bool $sameKey): void
    {
        self::assertSame(0, DB::transactionLevel());
        $ledger = app(DoubleEntryLedgerService::class);
        $asset = $ledger->getSystemAccount('RACE-LEDGER-ASSET-THB', 'Race asset', 'asset', 'THB');
        $liability = $ledger->getSystemAccount('RACE-LEDGER-LIABILITY-THB', 'Race liability', 'liability', 'THB');
        $seed = $ledger->postTransaction('LEDGER_RACE_SEED', 'seed', 'THB', [
            ['account_id' => $asset->id, 'direction' => 'DEBIT', 'amount' => '1.00'],
            ['account_id' => $liability->id, 'direction' => 'CREDIT', 'amount' => '1.00'],
        ], 'ledger-race-seed');

        $directory = storage_path('framework/testing/ledger-chain-race/'.bin2hex(random_bytes(12)));
        mkdir($directory, 0700, true);
        $processes = [];
        $files = [];
        $db = DB::connection();
        $code = <<<'PHP'
try {
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = $app->make('db')->connection();
    if (! app()->environment('testing') || $db->getDatabaseName() !== 'thai_lottery_test'
        || $db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
        || $db->transactionLevel() !== 0) {
        throw new RuntimeException('Unsafe ledger race environment.');
    }
    $file = $argv[1];
    $input = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    file_put_contents($file.'.ready', (string) $db->selectOne('SELECT CONNECTION_ID() AS id')->id);
    $deadline = microtime(true) + 25;
    while (! is_file(dirname($file).'/go') && microtime(true) < $deadline) { usleep(10000); }
    if (! is_file(dirname($file).'/go')) { throw new RuntimeException('Ledger race barrier timeout.'); }
    try {
        $tx = app(\App\Services\Ledger\DoubleEntryLedgerService::class)->postTransaction(
            'LEDGER_RACE_POST', $input['reference'], 'THB', [
                ['account_id' => $input['asset'], 'direction' => 'DEBIT', 'amount' => '5.00'],
                ['account_id' => $input['liability'], 'direction' => 'CREDIT', 'amount' => '5.00'],
            ], $input['key']);
        echo json_encode(['outcome' => 'posted', 'id' => $tx->id], JSON_THROW_ON_ERROR);
    } catch (\Illuminate\Database\QueryException $exception) {
        // A duplicate must be an actual MySQL/MariaDB unique-key violation,
        // not a connection, deadlock, foreign-key or fixture failure.
        if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) { throw $exception; }
        echo json_encode(['outcome' => 'duplicate'], JSON_THROW_ON_ERROR);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
    exit(1);
}
PHP;
        try {
            foreach ([0, 1] as $index) {
                $file = $directory.'/child-'.$index.'.json';
                file_put_contents($file, json_encode([
                    'asset' => $asset->id, 'liability' => $liability->id,
                    'reference' => $sameKey ? 'race-same' : 'race-'.$index,
                    'key' => $sameKey ? 'ledger-race-post-same' : 'ledger-race-post-'.$index,
                ], JSON_THROW_ON_ERROR));
                chmod($file, 0600);
                $files[] = $file;
                $process = new Process([PHP_BINARY, '-r', $code, $file], base_path(), [
                    'APP_BASE_PATH' => base_path(), 'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'),
                    'DB_CONNECTION' => $db->getName(), 'DB_DATABASE' => 'thai_lottery_test',
                    'DB_SOCKET' => (string) $db->getConfig('unix_socket'), 'DB_HOST' => 'localhost',
                    'DB_USERNAME' => (string) $db->getConfig('username'), 'DB_PASSWORD' => (string) $db->getConfig('password'),
                    'DB_URL' => '', 'CACHE_STORE' => 'array', 'LOG_CHANNEL' => 'null',
                ]);
                $process->setTimeout(40);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 15;
            while ((! is_file($files[0].'.ready') || ! is_file($files[1].'.ready')) && microtime(true) < $deadline) {
                foreach ($processes as $process) {
                    if (! $process->isRunning()) {
                        self::fail('Ledger race child failed before barrier: '.$process->getErrorOutput());
                    }
                }
                usleep(10000);
            }
            $ids = [];
            foreach ($files as $file) {
                self::assertFileExists($file.'.ready');
                $ids[] = (int) file_get_contents($file.'.ready');
            }
            self::assertNotSame($ids[0], $ids[1]);
            $parent = (int) $db->selectOne('SELECT CONNECTION_ID() AS id')->id;
            $db->beginTransaction();
            self::assertNotNull($db->table('ledger_transactions')->where('id', $seed->id)->lockForUpdate()->first());
            file_put_contents($directory.'/go', 'go');
            $deadline = microtime(true) + 12;
            $waiting = 0;
            while ($waiting !== 2 && microtime(true) < $deadline) {
                $waiting = InnoDbLockWitness::waiting($db, $ids, $parent, 'ledger_transactions');
                if ($waiting !== 2) {
                    usleep(200000);
                }
            }
            $db->rollBack();
            self::assertSame(2, $waiting, 'Both independent writers must wait on the real chain-tip row.');

            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                self::assertSame('', $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            $outcomes = array_column($results, 'outcome');
            sort($outcomes);
            self::assertSame($sameKey ? ['duplicate', 'posted'] : ['posted', 'posted'], $outcomes);
            $posted = array_values(array_filter($results, fn (array $result): bool => $result['outcome'] === 'posted'));
            $count = $sameKey ? 1 : 2;
            self::assertCount($count, $posted);
            self::assertSame($count + 1, LedgerTransaction::query()->count());
            self::assertSame($count + 1, DB::table('financial_transactions')->count());
            self::assertSame(2 * ($count + 1), LedgerEntry::query()->count());
            self::assertSame($sameKey ? '6.00' : '11.00', $asset->fresh()->current_balance);
            self::assertSame($sameKey ? '6.00' : '11.00', $liability->fresh()->current_balance);

            $first = LedgerTransaction::query()->where('previous_hash', $seed->hash)->sole();
            $chain = [$seed, $first];
            if (! $sameKey) {
                $chain[] = LedgerTransaction::query()->where('previous_hash', $first->hash)->sole();
            }
            self::assertEqualsCanonicalizing(array_column($posted, 'id'), array_map(fn ($tx) => $tx->id, array_slice($chain, 1)));
            foreach ($chain as $tx) {
                $entries = $tx->entries()->get();
                self::assertCount(2, $entries);
                self::assertSame(0, bccomp((string) $entries->where('direction', 'DEBIT')->sole()->amount,
                    (string) $entries->where('direction', 'CREDIT')->sole()->amount, 2));
            }
            $witness = getenv('LEDGER_RACE_WITNESS');
            if (is_string($witness) && $witness !== '') {
                file_put_contents($witness, json_encode([
                    'waiting_children' => $waiting, 'child_connections' => $ids,
                    'parent_blocker' => $parent, 'committed_postings' => $count,
                    'same_idempotency_key' => $sameKey,
                ], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
            }
        } finally {
            if ($db->transactionLevel() > 0) {
                $db->rollBack();
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
