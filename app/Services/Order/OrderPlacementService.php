<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Jobs\Commission\DistributeAgentCommissionsJob;
use App\Models\Draw;
use App\Models\LotteryEntry;
use App\Services\Lock\DistributedLockService;
use App\Services\Wallet\WalletMutationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Class OrderPlacementService
 *
 * High-concurrency, race-condition immune lottery entry and ticket placement engine.
 */
class OrderPlacementService
{
    public function __construct(
        private readonly DistributedLockService $lockService,
        private readonly WalletMutationService $walletMutationService
    ) {}

    /**
     * Place a high-speed lottery entry with distributed lock & double-entry deduction.
     *
     * @param string $userId
     * @param string $drawId
     * @param string $ticketNumber E.g. '592314' or '88'
     * @param string $betType E.g. 'DIRECT_6D', 'BACK_2D', 'FRONT_3D'
     * @param string $stakeAmount E.g. '100.0000'
     * @param string|null $agentId
     * @param string|null $idempotencyKey
     * @return LotteryEntry
     */
    public function placeOrder(
        string $userId,
        string $drawId,
        string $ticketNumber,
        string $betType,
        string $stakeAmount,
        ?string $agentId = null,
        ?string $idempotencyKey = null
    ): LotteryEntry {
        if (bccomp($stakeAmount, '1.0000', 4) < 0) {
            throw new InvalidArgumentException("Minimum stake amount is 1.0000 THB. Provided: {$stakeAmount}");
        }

        $idempotencyKey ??= 'ENTRY-IDEM-' . Str::uuid()->toString();

        // 1. Acquire Distributed Redis Lock on User Wallet to serialize concurrent clicks
        return $this->lockService->runWithLock("wallet:user:{$userId}", function () use (
            $userId,
            $drawId,
            $ticketNumber,
            $betType,
            $stakeAmount,
            $agentId,
            $idempotencyKey
        ) {
            return DB::transaction(function () use (
                $userId,
                $drawId,
                $ticketNumber,
                $betType,
                $stakeAmount,
                $agentId,
                $idempotencyKey
            ) {
                // Check for existing entry with same idempotency key
                $existingEntry = LotteryEntry::where('idempotency_key', $idempotencyKey)->first();
                if ($existingEntry) {
                    return $existingEntry;
                }

                // 2. Validate Draw Status and lock draw row for counter update
                /** @var Draw $draw */
                $draw = Draw::where('id', $drawId)->lockForUpdate()->firstOrFail();

                if (!$draw->isOpenForBetting()) {
                    throw new RuntimeException("Draw [{$draw->draw_code}] is closed or not available for betting.");
                }

                $entryId = Str::uuid()->toString();
                $voucherNumber = 'VCH-' . date('Ymd') . '-' . strtoupper(Str::random(10));

                // 3. Deduct Wallet Balance with Pessimistic Lock & Double-Entry Ledger Posting
                $this->walletMutationService->debit(
                    userId: $userId,
                    amount: $stakeAmount,
                    referenceType: 'LOTTERY_ENTRY',
                    referenceId: $entryId,
                    currency: 'THB',
                    walletType: 'main',
                    idempotencyKey: $idempotencyKey,
                    description: "Bet placement on Draw {$draw->draw_code} [{$betType} - {$ticketNumber}]"
                );

                // 4. Calculate chunk index for massive parallel settlement partitioning
                $chunkIndex = (int) floor($draw->total_entries_count / 1000);

                // 5. Create immutable lottery entry
                $entry = LotteryEntry::create([
                    'id' => $entryId,
                    'draw_id' => $draw->id,
                    'user_id' => $userId,
                    'agent_id' => $agentId,
                    'voucher_number' => $voucherNumber,
                    'ticket_number' => $ticketNumber,
                    'bet_type' => $betType,
                    'stake_amount' => $stakeAmount,
                    'multiplier' => '1.00',
                    'potential_win_amount' => $this->calculatePotentialWin($betType, $stakeAmount),
                    'actual_win_amount' => '0.0000',
                    'status' => 'PENDING',
                    'idempotency_key' => $idempotencyKey,
                    'chunk_index' => $chunkIndex,
                    'placed_at' => now(),
                ]);

                // 6. Update Draw aggregates
                $draw->total_entries_count += 1;
                $draw->total_pool_amount = bcadd($draw->total_pool_amount, $stakeAmount, 4);
                $draw->version += 1;
                $draw->save();

                // 7. Dispatch asynchronous multi-tier agent commission distribution
                if ($agentId) {
                    DistributeAgentCommissionsJob::dispatch($entry->id);
                }

                return $entry;
            }, attempts: 3);
        });
    }

    /**
     * Calculate potential win based on bet type odds.
     */
    private function calculatePotentialWin(string $betType, string $stakeAmount): string
    {
        $payoutMultiplier = match ($betType) {
            'DIRECT_6D' => '60000.00',
            'FRONT_3D', 'BACK_3D' => '450.00',
            'BACK_2D' => '90.00',
            'RUNNING_NUMBER' => '3.20',
            default => '1.00',
        };

        return bcmul($stakeAmount, $payoutMultiplier, 4);
    }
}
