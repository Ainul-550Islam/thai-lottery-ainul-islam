<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\DTOs\Lottery\WinningEntryResult;
use App\Models\DrawResult;
use App\Models\LotteryEntry;
use App\Models\Payout;
use App\Services\Broadcast\RealtimeSyncService;
use App\Services\Ledger\DoubleEntryLedgerService;
use App\Services\Lock\DistributedLockService;
use App\Services\Wallet\WalletMutationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BatchWinningSettlementService
{
    public function __construct(
        private readonly GloPrizeMatchingEngine $matchingEngine,
        private readonly DoubleEntryLedgerService $ledgerService,
        private readonly WalletMutationService $walletService,
        private readonly DistributedLockService $lockService,
        private readonly RealtimeSyncService $realtimeSyncService
    ) {}

    /**
     * Executes chunked settlement over all pending entries for a draw.
     *
     * @param  DrawResult  $drawResult  Confirmed Draw Result Model
     * @param  int  $chunkSize  Size of database batch chunk (default 1000)
     * @return array{total_processed: int, total_winners: int, total_payout_thb: string}
     */
    public function settleDrawEntries(DrawResult $drawResult, int $chunkSize = 1000): array
    {
        $drawId = $drawResult->draw_id;
        $totalProcessed = 0;
        $totalWinners = 0;
        $totalPayoutThb = '0.0000';

        $drawPayload = [
            'first_prize' => (string) $drawResult->first_prize,
            'three_digit_front' => (array) $drawResult->three_digit_front,
            'three_digit_back' => (array) $drawResult->three_digit_back,
            'two_digit_bottom' => (string) $drawResult->two_digit_bottom,
            'second_prizes' => (array) ($drawResult->second_prizes ?? []),
            'third_prizes' => (array) ($drawResult->third_prizes ?? []),
            'fourth_prizes' => (array) ($drawResult->fourth_prizes ?? []),
            'fifth_prizes' => (array) ($drawResult->fifth_prizes ?? []),
        ];

        Log::info("Starting batch winning settlement for Draw [{$drawId}].");

        LotteryEntry::where('draw_id', $drawId)
            ->where('status', 'PENDING')
            ->chunkById($chunkSize, function ($entries) use (
                $drawPayload,
                &$totalProcessed,
                &$totalWinners,
                &$totalPayoutThb
            ) {
                foreach ($entries as $entry) {
                    $totalProcessed++;
                    $eval = $this->matchingEngine->evaluateEntry(
                        betType: $entry->bet_type,
                        chosenNumber: (string) $entry->number,
                        betAmount: (string) $entry->amount,
                        drawResult: $drawPayload
                    );

                    if ($eval->isWinner) {
                        $totalWinners++;
                        $totalPayoutThb = bcadd($totalPayoutThb, $eval->netPayoutAmount, 4);

                        $this->settleWinningEntry($entry, $eval);
                    } else {
                        $entry->update([
                            'status' => 'LOST',
                            'settled_at' => now(),
                        ]);
                    }
                }
            });

        Log::info("Completed batch winning settlement for Draw [{$drawId}]: {$totalProcessed} processed, {$totalWinners} winners, Total Payout: {$totalPayoutThb} THB.");

        return [
            'total_processed' => $totalProcessed,
            'total_winners' => $totalWinners,
            'total_payout_thb' => $totalPayoutThb,
        ];
    }

    /**
     * Atomically pays out a winning entry via Double-Entry Ledger and Wallet Credit.
     */
    private function settleWinningEntry(LotteryEntry $entry, WinningEntryResult $eval): void
    {
        $idempotencyKey = "PAYOUT-ENTRY-{$entry->id}";

        DB::transaction(function () use ($entry, $eval, $idempotencyKey) {
            $payoutUuid = (string) Str::uuid();

            // 1. Post to Double-Entry Ledger:
            // Debit: Lottery Prize Expense Account
            // Credit: User Liability Account (User's balance in system)
            $prizeExpenseAccount = $this->ledgerService->getSystemAccount(
                accountNumber: 'SYS-EXPENSE-LOTTERY-PRIZES-THB',
                name: 'Lottery Prize Payout Expense [THB]',
                type: 'EXPENSE',
                currency: 'THB'
            );
            $userLiabilityAccount = $this->ledgerService->getOrCreateUserAccount($entry->user_id, 'THB');

            $ledgerTx = $this->ledgerService->postTransaction(
                referenceType: 'PRIZE_PAYOUT',
                referenceId: $payoutUuid,
                currency: 'THB',
                entries: [
                    [
                        'account_id' => $prizeExpenseAccount->id,
                        'direction' => 'DEBIT',
                        'amount' => $eval->netPayoutAmount,
                    ],
                    [
                        'account_id' => $userLiabilityAccount->id,
                        'direction' => 'CREDIT',
                        'amount' => $eval->netPayoutAmount,
                    ],
                ],
                idempotencyKey: $idempotencyKey,
                description: "Prize payout for entry #{$entry->id} [{$eval->description}]"
            );

            // 2. Direct Atomic Wallet Balance Increment
            $this->walletService->credit(
                userId: $entry->user_id,
                amount: $eval->netPayoutAmount,
                referenceType: 'PRIZE_PAYOUT',
                referenceId: $payoutUuid,
                currency: 'THB',
                walletType: 'main',
                idempotencyKey: "WALLET-{$idempotencyKey}",
                description: "Winning payout: {$eval->description}"
            );

            // 3. Persist Payout Record
            Payout::create([
                'id' => $payoutUuid,
                'lottery_entry_id' => $entry->id,
                'user_id' => $entry->user_id,
                'draw_id' => $entry->draw_id,
                'prize_tier' => $eval->prizeTier?->value ?? 'CUSTOM',
                'gross_amount' => $eval->grossPayoutAmount,
                'wht_tax_amount' => $eval->whtTaxAmount,
                'net_amount' => $eval->netPayoutAmount,
                'currency' => 'THB',
                'payout_status' => 'COMPLETED',
                'ledger_transaction_id' => $ledgerTx->id,
                'paid_at' => now(),
            ]);

            // 4. Update Entry Status
            $entry->update([
                'status' => 'WON',
                'payout_amount' => $eval->netPayoutAmount,
                'settled_at' => now(),
            ]);
        });
    }
}
