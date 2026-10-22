<?php

declare(strict_types=1);
use App\Models\Wallet;
use App\Services\Finance\FinancialTransactionService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletLockService;
use App\Services\Finance\WalletService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;

// Only isolated local disposable acceptance; no provider calls or remote sockets.
$root = dirname(__DIR__, 2);
if (getenv('FINANCIAL_RETRY_PROBE') !== '1' || getenv('APP_ENV') !== 'testing'
    || getenv('DB_DATABASE') !== 'thai_lottery_test' || getenv('DB_URL')
    || ! in_array(getenv('DB_CONNECTION'), ['mysql', 'mariadb'], true)
    || ! is_string(getenv('DB_SOCKET')) || @filetype(getenv('DB_SOCKET')) !== 'socket'
    || is_file($root.'/bootstrap/cache/config.php')) {
    fwrite(STDERR, 'Unsafe financial retry probe environment.');
    exit(2);
}
$input = realpath($argv[1] ?? '');
$scratch = realpath($root.'/storage/framework/testing/financial-retries');
if (! $input || ! $scratch || ! str_starts_with($input, $scratch.'/') || filesize($input) > 8192) {
    fwrite(STDERR, 'Unsafe retry probe path.');
    exit(2);
}
try {
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $db = $app['db']->connection();
    if ($db->getDatabaseName() !== 'thai_lottery_test' || $db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException('Unsafe retry probe connection.');
    }
    $payload = json_decode(file_get_contents($input), true, 16, JSON_THROW_ON_ERROR);
    if (! in_array($payload['mode'], ['root', 'ambient'], true) || ! in_array($payload['fault'], ['deadlock', 'timeout'], true)) {
        throw new RuntimeException('Unsafe retry profile.');
    }
    $app['config']->set('finance.locking.max_retries', 3);
    $app['config']->set('finance.locking.retry_delay_milliseconds', 0);
    $db->statement('SET SESSION innodb_lock_wait_timeout = 4');
    $directory = dirname($input);
    $wait = function (string $file): void {
        $deadline = microtime(true) + 25;
        while (! is_file($file) && microtime(true) < $deadline) {
            usleep(5000);
        }
        if (! is_file($file)) {
            throw new RuntimeException('Retry test barrier timed out.');
        }
    };
    $wallet = Wallet::query()->findOrFail($payload['wallet_id']);
    $connectionId = (int) $db->selectOne('SELECT CONNECTION_ID() AS id')->id;
    $ambient = $payload['mode'] === 'ambient';
    if ($ambient) {
        $db->beginTransaction();
        $db->table('users')->where('id', $wallet->user_id)->update(['name' => 'committable caller marker']);
    }
    $attempts = 0;
    $faults = [];
    $transactions = $app->make(FinancialTransactionService::class);
    $locks = $app->make(WalletLockService::class);
    $key = 'synthetic-native-retry:'.$payload['token'].':'.$wallet->id;
    try {
        $result = $transactions->executeWithDeadlockRetry(function () use ($locks, $app, $db, $wallet, $payload, &$attempts, &$faults, $input, $directory, $connectionId, $wait, $key) {
            return $locks->withinTransaction(function () use ($app, $db, $wallet, $payload, &$attempts, &$faults, $input, $directory, $connectionId, $wait, $key) {
                $attempts++;
                $current = $app->make(WalletLockService::class)->lock($wallet->id);
                $before = $current->balance;
                $transaction = $app->make(WalletService::class)->credit($current,
                    Money::of('10.00', $wallet->currency), idempotencyKey: $key);
                if ($attempts === 1) {
                    $ready = ['connection_id' => $connectionId, 'balance_before' => $before, 'balance_staged' => $current->fresh()->balance,
                        'level' => $db->transactionLevel(), 'physical' => $db->getPdo()->inTransaction(), 'transaction_id' => $transaction->id];
                    file_put_contents($input.'.ready.tmp', json_encode($ready, JSON_THROW_ON_ERROR), LOCK_EX);
                    rename($input.'.ready.tmp', $input.'.ready');
                    $wait($directory.'/go');
                }
                try {
                    $db->table('wallets')->where('id', $payload['other_wallet_id'])->lockForUpdate()->first();
                } catch (QueryException $exception) {
                    $faults[] = ['code' => $exception->errorInfo[1] ?? null, 'level' => $db->transactionLevel(), 'physical' => $db->getPdo()->inTransaction(),
                        'attempt' => $attempts, 'balance_before' => $before];
                    if ($payload['fault'] === 'timeout' && $attempts === 1) {
                        file_put_contents($input.'.timeout', json_encode(end($faults), JSON_THROW_ON_ERROR), LOCK_EX);
                        $wait($directory.'/retry-go');
                    }
                    throw $exception;
                }

                return $transaction;
            });
        });
        $outcome = 'completed';
        if ($ambient) {
            $db->commit();
        }
    } catch (QueryException|DeadlockException $exception) {
        $outcome = 'refused';
        // Only the caller may end its root after a real engine-wide abort.
        $cause = $exception;
        while ($cause instanceof DeadlockException && $cause->getPrevious()) {
            $cause = $cause->getPrevious();
        }
        $aborted = $cause instanceof QueryException && (string) ($cause->errorInfo[1] ?? '') === '1213';
        if (! $aborted && $db->getPdo()->inTransaction()) {
            $db->commit();
        } else {
            $db->rollBack(0);
        }
    }
    echo json_encode(['outcome' => $outcome, 'attempts' => $attempts, 'faults' => $faults,
        'balance' => $wallet->fresh()->balance, 'currency' => $wallet->currency->value, 'caller_marker' => $wallet->user->fresh()->name,
        'level' => $db->transactionLevel(), 'physical' => $db->getPdo()->inTransaction(), 'key' => $key], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
    exit(2);
}
