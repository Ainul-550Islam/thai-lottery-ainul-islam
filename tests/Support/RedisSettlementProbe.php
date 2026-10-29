<?php

declare(strict_types=1);

use App\Enums\QueueName;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Console\Output\BufferedOutput;

$root = dirname(__DIR__, 2);
$input = realpath($argv[1] ?? '');
$scratch = realpath($root.'/storage/framework/testing/redis-settlement');
$redis = getenv('REDIS_HOST');
if (getenv('REDIS_SETTLEMENT_PROBE') !== '1' || getenv('APP_ENV') !== 'testing'
    || getenv('DB_DATABASE') !== 'thai_lottery_test' || getenv('DB_URL') || getenv('REDIS_URL')
    || ! in_array(getenv('DB_CONNECTION'), ['mysql', 'mariadb'], true)
    || @filetype((string) getenv('DB_SOCKET')) !== 'socket'
    || ! is_string($redis) || ! str_starts_with($redis, dirname($root).'/.cache/part25-redis/')
    || @filetype($redis) !== 'socket' || getenv('REDIS_PORT') !== '0'
    || ! $input || ! $scratch || ! str_starts_with($input, $scratch.DIRECTORY_SEPARATOR)
    || filesize($input) > 4096 || is_file($root.'/bootstrap/cache/config.php')) {
    fwrite(STDERR, 'Redis settlement probe refused unsafe input.');
    exit(2);
}
try {
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (DB::connection()->getDatabaseName() !== 'thai_lottery_test'
        || DB::connection()->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException('Unsafe test schema.');
    }
    $payload = json_decode(file_get_contents($input), true, 16, JSON_THROW_ON_ERROR);
    if (! in_array($payload['phase'], ['none', 'committed', 'released'], true)
        || ! is_int($payload['time']) || ! in_array($payload['threshold'], ['0.00', '1000000.00'], true)) {
        throw new RuntimeException('Unsafe test phase.');
    }
    Carbon::setTestNow(Carbon::createFromTimestamp($payload['time']));
    config(['queue.default' => 'redis', 'cache.default' => 'redis', 'finance.prize_payout.safety_mode' => 'LIVE',
        'finance.prize_payout.auto_approve_below' => $payload['threshold'],
        'finance.prize_payout.require_approval_at_or_above' => $payload['threshold']]);
    $app->make('redis')->enableEvents();
    $checkpoint = false;
    $paused = false;
    $pause = static function (string $phase) use ($input, &$paused): void {
        if ($paused) {
            return;
        }
        $paused = true;
        file_put_contents($input.'.paused.tmp', json_encode(['phase' => $phase, 'pid' => getmypid(),
            'connection' => (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id,
            'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR));
        rename($input.'.paused.tmp', $input.'.paused');
        posix_kill(getmypid(), SIGSTOP);
    };
    DB::listen(static function (QueryExecuted $event) use (&$checkpoint): void {
        if (str_starts_with(strtolower($event->sql), 'update') && str_contains($event->sql, 'settlement_runs')) {
            $checkpoint = true;
        }
    });
    Event::listen(TransactionCommitted::class, static function () use (&$checkpoint, $payload, $pause): void {
        if ($checkpoint && DB::transactionLevel() === 0 && $payload['phase'] === 'committed') {
            $pause('committed');
        }
    });
    Event::listen(CommandExecuted::class, static function (CommandExecuted $event) use ($payload, $pause): void {
        if ($payload['phase'] === 'released' && $event->connectionName === 'cache'
            && $event->command === 'eval' && str_contains((string) ($event->parameters[0] ?? ''), 'redis.call("get"')
            && str_contains((string) ($event->parameters[0] ?? ''), 'redis.call("del"')) {
            $pause('released');
        }
    });
    $exit = $app->make(Kernel::class)->call('queue:work', ['connection' => 'redis',
        '--queue' => QueueName::workerQueueString(), '--sleep' => 0, '--tries' => 3, '--timeout' => 180,
        '--stop-when-empty' => true, '--max-jobs' => 1, '--no-interaction' => true], new BufferedOutput);
    echo json_encode(['worked' => true, 'worker_exit' => $exit], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    echo json_encode(['worked' => false, 'exception' => $exception::class,
        'location' => basename($exception->getFile()).':'.$exception->getLine()], JSON_THROW_ON_ERROR);
    exit(1);
}
