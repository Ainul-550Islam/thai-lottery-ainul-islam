<?php

declare(strict_types=1);

use App\Enums\QueueName;
use App\Models\SettlementRun;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Console\Output\BufferedOutput;

// Test-only daemon bootstrap; no production route or command registers it.
$root = dirname(__DIR__, 2);
$input = realpath($argv[1] ?? '');
$scratch = realpath($root.'/storage/framework/testing/settlement-recovery');
$socket = getenv('DB_SOCKET');
if (getenv('SETTLEMENT_RECOVERY_PROBE') !== '1' || getenv('APP_ENV') !== 'testing'
    || getenv('DB_DATABASE') !== 'thai_lottery_test' || getenv('DB_URL')
    || ! in_array(getenv('DB_CONNECTION'), ['mysql', 'mariadb'], true)
    || ! is_string($socket) || ! str_starts_with($socket, '/') || @filetype($socket) !== 'socket'
    || is_file($root.'/bootstrap/cache/config.php') || ! $input || ! $scratch
    || ! str_starts_with($input, $scratch.DIRECTORY_SEPARATOR) || filesize($input) > 4096) {
    fwrite(STDERR, 'Settlement recovery probe refused unsafe input.');
    exit(2);
}
try {
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (! function_exists('posix_kill') || ! function_exists('pcntl_signal')
        || DB::connection()->getDatabaseName() !== 'thai_lottery_test'
        || DB::connection()->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException('Unsupported isolated test runtime.');
    }
    $payload = json_decode(file_get_contents($input), true, 16, JSON_THROW_ON_ERROR);
    if (! in_array($payload['phase'], ['none', 'money', 'committed', 'enqueued', 'finalized', 'started'], true)
        || ! is_int($payload['time']) || ! in_array($payload['reservation_lag'], [0, 25], true)
        || ! in_array($payload['threshold'], ['0.00', '1000000.00'], true)) {
        throw new RuntimeException('Unsupported fault injection.');
    }
    Carbon::setTestNow(Carbon::createFromTimestamp($payload['time']));
    config(['cache.default' => 'database', 'queue.default' => 'database',
        'finance.prize_payout.safety_mode' => 'LIVE',
        'finance.prize_payout.auto_approve_below' => $payload['threshold'],
        'finance.prize_payout.require_approval_at_or_above' => $payload['threshold']]);
    $connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
    $checkpoint = false;
    $created = false;
    $finalized = false;
    $paused = false;
    $pause = static function (string $phase) use (&$paused, $input, $connection): void {
        if ($paused) {
            return;
        }
        $paused = true;
        file_put_contents($input.'.paused.tmp', json_encode(['phase' => $phase, 'pid' => getmypid(),
            'connection' => $connection, 'transaction_level' => DB::transactionLevel(),
            'cache' => config('cache.default')], JSON_THROW_ON_ERROR));
        rename($input.'.paused.tmp', $input.'.paused');
        posix_kill(getmypid(), SIGSTOP); // Parent verifies state, then sends real SIGKILL.
    };
    Event::listen(JobProcessing::class, static function () use ($payload): void {
        // Accelerates a 25s reservation-to-lock scheduling stall, not SQL visibility.
        Carbon::setTestNow(Carbon::createFromTimestamp($payload['time'] + $payload['reservation_lag']));
    });
    DB::listen(static function (QueryExecuted $event) use (&$checkpoint, &$created, &$finalized, $payload, $pause): void {
        $sql = strtolower($event->sql);
        if (str_starts_with($sql, 'insert') && str_contains($sql, 'ledger_entries') && $payload['phase'] === 'money') {
            $pause('money');
        }
        if (str_starts_with($sql, 'insert') && str_contains($sql, 'settlement_runs')) {
            $created = true;
        }
        if (str_starts_with($sql, 'update') && str_contains($sql, 'settlement_runs')) {
            $checkpoint = true;
            $finalized = in_array(SettlementRun::STATUS_COMPLETED, $event->bindings, true);
        }
        if ($checkpoint && str_starts_with($sql, 'insert') && str_contains($sql, '`jobs`') && $payload['phase'] === 'enqueued') {
            $pause('enqueued');
        }
    });
    Event::listen(TransactionCommitted::class, static function () use (&$checkpoint, &$created, &$finalized, $payload, $pause): void {
        if (DB::transactionLevel() !== 0) {
            return;
        }
        if (($payload['phase'] === 'committed' && $checkpoint)
            || ($payload['phase'] === 'finalized' && $finalized)
            || ($payload['phase'] === 'started' && $created)) {
            $pause($payload['phase']);
        }
    });
    $exit = $app->make(Kernel::class)->call('queue:work', [
        'connection' => 'database', '--queue' => QueueName::workerQueueString(),
        '--sleep' => 0, '--tries' => 3, '--timeout' => 180,
        '--stop-when-empty' => true, '--max-jobs' => 1, '--no-interaction' => true,
    ], new BufferedOutput);
    echo json_encode(['worked' => true, 'worker_exit' => $exit, 'connection' => $connection], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    echo json_encode(['worked' => false, 'exception' => $exception::class], JSON_THROW_ON_ERROR);
    exit(1);
}
