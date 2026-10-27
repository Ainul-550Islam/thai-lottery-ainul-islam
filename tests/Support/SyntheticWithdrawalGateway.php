<?php

declare(strict_types=1);

namespace Tests\Support;

use App\DTOs\Payment\GatewayWithdrawalResponse;
use App\Models\Withdrawal;
use App\Services\Payment\Drivers\BankTransferGateway;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Synthetic acceptance journal only; no network, payment endpoint or real beneficiary. */
final class SyntheticWithdrawalGateway extends BankTransferGateway
{
    public function __construct(Repository $config, private readonly string $directory,
        private readonly string $mode = 'pending', private readonly ?\Closure $accepted = null)
    {
        parent::__construct($config);
        $root = realpath(storage_path('framework/testing'));
        $path = realpath($directory);
        if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'thai_lottery_test'
            || ! $root || ! $path || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR)
            || ! in_array($mode, ['pending', 'timeout'], true)) {
            throw new RuntimeException('Synthetic outbound gateway refused unsafe scope.');
        }
    }

    public function initiateWithdrawal(Withdrawal $withdrawal, array $options = []): GatewayWithdrawalResponse
    {
        if (DB::transactionLevel() !== 0 || preg_match('/^[0-9a-f]{64}$/D', $options['idempotency_key'] ?? '') !== 1) {
            throw new RuntimeException('Synthetic gateway requires no SQL locks and one server-owned key.');
        }
        $stream = fopen($this->directory.'/accepted.jsonl', 'ab');
        if ($stream === false || ! flock($stream, LOCK_EX)) {
            throw new RuntimeException('Synthetic journal unavailable.');
        }
        try {
            $line = json_encode(['withdrawal' => (int) $withdrawal->id, 'key' => $options['idempotency_key'],
                'pid' => getmypid(), 'currency' => $withdrawal->currency->value,
                'gross' => (string) $withdrawal->amount, 'net' => (string) $withdrawal->net_amount], JSON_THROW_ON_ERROR)."\n";
            if (fwrite($stream, $line) !== strlen($line) || ! fflush($stream) || ! fsync($stream)) {
                throw new RuntimeException('Synthetic acceptance could not be persisted.');
            }
        } finally {
            flock($stream, LOCK_UN);
            fclose($stream);
        }
        if ($this->accepted !== null) {
            ($this->accepted)();
        }
        if ($this->mode === 'timeout') {
            throw new RuntimeException('Synthetic acknowledgement lost after journal acceptance.');
        }

        return GatewayWithdrawalResponse::pending('SYNTHETIC-DISPATCH-'.$withdrawal->id);
    }
}
