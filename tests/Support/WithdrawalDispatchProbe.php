<?php

declare(strict_types=1);

use App\Jobs\Payment\DisburseWithdrawalJob;
use App\Models\Withdrawal;
use App\Services\Payment\Drivers\BankTransferGateway;
use App\Services\Payment\WithdrawalDisbursementService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\SyntheticWithdrawalGateway;

$root = dirname(__DIR__, 2);
$input = realpath($argv[1] ?? '');
$scratch = realpath($root.'/storage/framework/testing/outbound-dispatch');
if (getenv('WITHDRAWAL_DISPATCH_PROBE') !== '1' || getenv('APP_ENV') !== 'testing'
    || getenv('DB_DATABASE') !== 'thai_lottery_test' || getenv('DB_URL')
    || ! in_array(getenv('DB_CONNECTION'), ['mysql', 'mariadb'], true)
    || @filetype((string) getenv('DB_SOCKET')) !== 'socket'
    || ! $input || ! $scratch || ! str_starts_with($input, $scratch.DIRECTORY_SEPARATOR)
    || filesize($input) > 4096 || is_file($root.'/bootstrap/cache/config.php')) {
    fwrite(STDERR, 'Withdrawal dispatch probe refused unsafe environment/input.');
    exit(2);
}
try {
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (DB::connection()->getDatabaseName() !== 'thai_lottery_test'
        || DB::connection()->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException('Unsafe test connection.');
    }
    $payload = json_decode(file_get_contents($input), true, 16, JSON_THROW_ON_ERROR);
    if (! is_int($payload['withdrawal'] ?? null) || ! in_array($payload['phase'] ?? null, ['claimed', 'accepted', 'none'], true)) {
        throw new RuntimeException('Unsafe test phase.');
    }
    config(['cache.default' => 'array', 'queue.default' => 'database', 'payment.gateways.bank_transfer.enabled' => true,
        'payment.gateways.bank_transfer.supports_withdrawal' => true]);
    $paused = false;
    $pause = static function (string $phase) use (&$paused, $input): void {
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
    if ($payload['phase'] === 'claimed') {
        Event::listen(TransactionCommitted::class, static function () use ($payload, $pause): void {
            if (DB::transactionLevel() === 0 && (Withdrawal::query()->findOrFail($payload['withdrawal'])->metadata['outbound_dispatch']['state'] ?? null) === 'sending') {
                $pause('claimed');
            }
        });
    }
    $accepted = $payload['phase'] === 'accepted' ? static fn () => $pause('accepted') : null;
    $app->instance(BankTransferGateway::class, new SyntheticWithdrawalGateway($app['config'], dirname($input), 'pending', $accepted));
    $response = (new DisburseWithdrawalJob($payload['withdrawal']))->handle($app->make(WithdrawalDisbursementService::class));
    echo json_encode(['worked' => true, 'unknown' => $response?->outcomeUnknown,
        'pending' => $response?->isPending, 'successful' => $response?->successful], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    echo json_encode(['worked' => false, 'exception' => $exception::class], JSON_THROW_ON_ERROR);
    exit(1);
}
