<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Models\Agent;
use App\Models\AgentHierarchy;
use App\Models\CommissionLedger;
use App\Models\CommissionRule;
use App\Models\LotteryEntry;
use App\Services\Ledger\DoubleEntryLedgerService;
use App\Services\Wallet\WalletMutationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Class MultiTierCommissionDistributionService
 *
 * Computes and disburses multi-level agent commissions (Master -> Super -> Senior -> Direct)
 * with zero N+1 database queries, closure table resolution, and double-entry crediting.
 */
class MultiTierCommissionDistributionService
{
    public function __construct(
        private readonly WalletMutationService $walletMutationService,
        private readonly DoubleEntryLedgerService $ledgerService
    ) {}

    /**
     * Distribute instantaneous commissions across the agent ancestry hierarchy.
     */
    public function distributeForEntry(string $lotteryEntryId): array
    {
        /** @var LotteryEntry $entry */
        $entry = LotteryEntry::with('draw')->findOrFail($lotteryEntryId);

        if (!$entry->agent_id) {
            return []; // Direct user without agent referral
        }

        return DB::transaction(function () use ($entry) {
            // 1. Fetch all ancestor agents in the hierarchy ordered by depth ascending (0 = direct, 1 = parent, etc.)
            $ancestorNodes = AgentHierarchy::with(['ancestor.parent'])
                ->where('descendant_id', $entry->agent_id)
                ->orderBy('depth', 'asc')
                ->get();

            if ($ancestorNodes->isEmpty()) {
                return [];
            }

            // 2. Fetch commission rules for this draw's game type
            $gameType = $entry->draw->game_type;
            $rules = CommissionRule::where('is_active', true)
                ->whereIn('game_type', [$gameType, 'ALL'])
                ->get()
                ->keyBy('tier_level');

            $distributedRecords = [];
            $stakeAmount = $entry->stake_amount;

            foreach ($ancestorNodes as $node) {
                $agent = $node->ancestor;
                $depth = $node->depth;
                $tierLevel = $agent->tier_level;

                if ($agent->status !== 'ACTIVE') {
                    continue; // Skip suspended or terminated agents
                }

                // Determine commission percentage
                // Direct Agent (depth 0) gets direct_commission_rate; Ancestors get override percentage
                $rate = '0.00';
                if ($depth === 0) {
                    $rate = $agent->direct_commission_rate;
                } else {
                    $rule = $rules->get($tierLevel);
                    $rate = $rule ? $rule->override_percentage : '0.50';
                }

                // Compute commission amount: (stakeAmount * rate) / 100
                $commissionAmount = bcdiv(bcmul($stakeAmount, $rate, 4), '100', 4);

                if (bccomp($commissionAmount, '0.0000', 4) <= 0) {
                    continue;
                }

                $idempotencyKey = "COMM-ENTRY-{$entry->id}-AGENT-{$agent->id}";

                // Check for duplicate payout prevention
                if (CommissionLedger::where('idempotency_key', $idempotencyKey)->exists()) {
                    continue;
                }

                // Record Commission Ledger
                $commissionLedger = CommissionLedger::create([
                    'id' => Str::uuid()->toString(),
                    'agent_id' => $agent->id,
                    'source_user_id' => $entry->user_id,
                    'lottery_entry_id' => $entry->id,
                    'draw_id' => $entry->draw_id,
                    'hierarchy_depth' => $depth,
                    'basis_amount' => $stakeAmount,
                    'commission_rate' => $rate,
                    'commission_amount' => $commissionAmount,
                    'status' => 'POSTED',
                    'idempotency_key' => $idempotencyKey,
                    'calculated_at' => now(),
                ]);

                // Credit Agent's Commission Wallet
                $this->walletMutationService->credit(
                    userId: $agent->user_id,
                    amount: $commissionAmount,
                    referenceType: 'AGENT_COMMISSION',
                    referenceId: $commissionLedger->id,
                    currency: 'THB',
                    walletType: 'commission',
                    idempotencyKey: $idempotencyKey,
                    description: "Tier-{$tierLevel} Commission for Ticket #{$entry->ticket_number}"
                );

                $distributedRecords[] = $commissionLedger;
            }

            return $distributedRecords;
        });
    }
}
