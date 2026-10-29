<?php

declare(strict_types=1);

namespace App\Jobs\Settlement;

use App\Enums\QueueName;
use App\Exceptions\LegacyLotteryEntryException;
use App\Services\Wallet\WalletMutationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Preserve the serialized job shape, but never trust a queued user, amount or
 * tier as proof of a prize. Existing LotteryEntry claims require reconciliation.
 */
class ProcessWinningEntryPayoutJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $backoff = 10;

    public int $timeout = 60;

    public function __construct(
        public readonly string $lotteryEntryId,
        public readonly int|string $drawId,
        public readonly int|string $userId,
        public readonly string $winAmount,
        public readonly string $winningTier
    ) {
        $this->onQueue(QueueName::FinancialCritical->value);
    }

    public function handle(WalletMutationService $walletMutationService): void
    {
        throw LegacyLotteryEntryException::unavailable('prize payout');
    }
}
