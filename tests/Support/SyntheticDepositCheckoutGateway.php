<?php

declare(strict_types=1);

namespace Tests\Support;

use App\DTOs\Payment\GatewayDepositResponse;
use App\Models\Deposit;
use App\Services\Payment\DepositCheckoutService;
use App\Services\Payment\Drivers\StripeGateway;
use Illuminate\Support\Facades\DB;

/** Local process-race adapter only. No HTTPS, bank, merchant or real money. */
final class SyntheticDepositCheckoutGateway extends StripeGateway
{
    public static string $logPath;

    public static bool $loseAcknowledgement = false;

    public function initiateDeposit(Deposit $deposit, array $options = []): GatewayDepositResponse
    {
        if (! app()->environment('testing') || getenv('FINANCIAL_RACE_PROBE') !== '1'
            || DB::connection()->getDatabaseName() !== 'thai_lottery_test'
            || DB::transactionLevel() !== 0 || DB::connection()->getPdo()->inTransaction()) {
            throw new \RuntimeException('Unsafe synthetic checkout context.');
        }
        $attempt = DB::table(DepositCheckoutService::TABLE)->where('deposit_id', $deposit->id)->sole();
        if ($attempt->state !== 'dispatching' || (int) $attempt->attempt_count !== 1
            || $attempt->dispatch_key !== ($options['checkout_dispatch_key'] ?? null)) {
            throw new \RuntimeException('Synthetic checkout did not observe a committed dispatch claim.');
        }
        $line = json_encode(['deposit_id' => $deposit->id, 'state' => $attempt->state,
            'attempt_count' => (int) $attempt->attempt_count, 'transaction_level' => DB::transactionLevel(),
            'connection_id' => (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id], JSON_THROW_ON_ERROR)."\n";
        if (file_put_contents(self::$logPath, $line, FILE_APPEND | LOCK_EX) !== strlen($line)) {
            throw new \RuntimeException('Synthetic external acceptance log failed.');
        }
        if (self::$loseAcknowledgement) {
            throw new \RuntimeException('SYNTHETIC acceptance with lost acknowledgement.');
        }

        return GatewayDepositResponse::redirect('https://example.invalid/synthetic-checkout', 'SYNTHETIC-CHECKOUT-'.$deposit->id);
    }
}
