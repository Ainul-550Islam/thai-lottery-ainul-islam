<?php

declare(strict_types=1);

namespace App\Jobs\Settlement;

use App\Enums\PayoutStatus;
use App\Enums\QueueName;
use App\Models\LotteryEntry;
use App\Models\Payout;
use App\Services\Wallet\WalletMutationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Class ProcessWinningEntryPayoutJob
 *
 * Highly resilient, idempotent financial payout job executed on dedicated payout queue.
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
        $idempotencyKey = "PAYOUT-DRAW-{$this->drawId}-ENTRY-{$this->lotteryEntryId}";

        DB::transaction(function () use ($walletMutationService, $idempotencyKey) {
            // Check if payout already exists (Idempotency guarantee)
            $existingPayout = Payout::where('payout_idempotency_key', $idempotencyKey)->first();
            if ($existingPayout && ($existingPayout->payout_status === 'COMPLETED' || $existingPayout->status === PayoutStatus::Completed)) {
                return;
            }

            /** @var LotteryEntry $entry */
            $entry = LotteryEntry::where('id', $this->lotteryEntryId)->lockForUpdate()->firstOrFail();

            if ($entry->status === 'SETTLED') {
                return; // Already settled
            }

            $payoutUuid = Str::uuid()->toString();
            $payoutNumber = 'PAY-'.date('Ymd').'-'.strtoupper(Str::random(10));

            // Tax withholding logic (e.g. 0.5% government tax or 0% for standard)
            $taxWithheld = '0.0000';
            $netPayoutAmount = $this->winAmount;

            // Credit User Wallet via Double-Entry Ledger
            $wallet = $walletMutationService->credit(
                userId: (string) $this->userId,
                amount: $netPayoutAmount,
                referenceType: 'PRIZE_PAYOUT',
                referenceId: $payoutUuid,
                currency: 'THB',
                walletType: 'main',
                idempotencyKey: $idempotencyKey,
                description: "Prize payout for Draw [{$this->drawId}], Entry #{$entry->ticket_number} ({$this->winningTier})"
            );

            // Record Payout
            Payout::create([
                'draw_id' => (int) $this->drawId,
                'lottery_entry_id' => $this->lotteryEntryId,
                'user_id' => (int) $this->userId,
                'wallet_id' => $wallet->id,
                'payout_number' => $payoutNumber,
                'winning_tier' => $this->winningTier,
                'prize_tier' => $this->winningTier,
                'gross_payout_amount' => $this->winAmount,
                'gross_amount' => $this->winAmount,
                'tax_withheld' => $taxWithheld,
                'wht_tax_amount' => $taxWithheld,
                'net_payout_amount' => $netPayoutAmount,
                'net_amount' => $netPayoutAmount,
                'amount' => bcadd($netPayoutAmount, '0', 2),
                'currency' => 'THB',
                'status' => 'COMPLETED',
                'payout_status' => 'COMPLETED',
                'payout_idempotency_key' => $idempotencyKey,
                'settled_at' => now(),
                'paid_at' => now(),
            ]);

            // Mark entry as settled
            $entry->status = 'SETTLED';
            $entry->save();

            Log::info("Payout successfully completed for entry [{$this->lotteryEntryId}], Amount: {$netPayoutAmount} THB.");
        }, attempts: 3);
    }
}
