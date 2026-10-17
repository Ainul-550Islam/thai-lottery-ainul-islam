<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\Settlement\ChunkedSettlementJob;
use App\Jobs\Settlement\ProcessWinningEntryPayoutJob;
use App\Models\Agent;
use App\Models\AgentHierarchy;
use App\Models\CommissionRule;
use App\Models\Draw;
use App\Models\LotteryEntry;
use App\Models\Payout;
use App\Models\Wallet;
use App\Services\Broadcast\RealtimeSyncService;
use App\Services\Commission\MultiTierCommissionDistributionService;
use App\Services\Deposit\DepositProcessingService;
use App\Services\Ledger\LedgerReconciliationService;
use App\Services\Order\OrderPlacementService;
use App\Services\ResultIngestion\DrawResultConfirmationService;
use App\Services\ResultIngestion\GloResultIngestionService;
use App\Services\Wallet\WalletMutationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EndToEndFinancialLifecycleProofTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_fintech_lifecycle_chain_maintains_exact_ledger_invariants(): void
    {
        $depositService = app(DepositProcessingService::class);
        $orderService = app(OrderPlacementService::class);
        $commissionService = app(MultiTierCommissionDistributionService::class);
        $ingestionService = app(GloResultIngestionService::class);
        $confirmationService = app(DrawResultConfirmationService::class);
        $walletMutationService = app(WalletMutationService::class);
        $reconciliationService = app(LedgerReconciliationService::class);
        $syncService = app(RealtimeSyncService::class);

        // 1. Initial State: Setup 3-tier Agent Structure + Bettor
        $userId = Str::uuid()->toString();
        $masterUserId = Str::uuid()->toString();
        $superUserId = Str::uuid()->toString();
        $directUserId = Str::uuid()->toString();

        $userWallet = Wallet::create(['id' => Str::uuid()->toString(), 'user_id' => $userId, 'type' => 'main', 'currency' => 'THB', 'status' => 'active']);
        $masterWallet = Wallet::create(['id' => Str::uuid()->toString(), 'user_id' => $masterUserId, 'type' => 'commission', 'currency' => 'THB', 'status' => 'active']);
        $superWallet = Wallet::create(['id' => Str::uuid()->toString(), 'user_id' => $superUserId, 'type' => 'commission', 'currency' => 'THB', 'status' => 'active']);
        $directWallet = Wallet::create(['id' => Str::uuid()->toString(), 'user_id' => $directUserId, 'type' => 'commission', 'currency' => 'THB', 'status' => 'active']);

        $masterAgent = Agent::create(['id' => Str::uuid()->toString(), 'user_id' => $masterUserId, 'agent_code' => 'M-HQ', 'tier_level' => 1, 'direct_commission_rate' => '10.00', 'status' => 'ACTIVE']);
        $superAgent = Agent::create(['id' => Str::uuid()->toString(), 'user_id' => $superUserId, 'agent_code' => 'S-BKK', 'parent_agent_id' => $masterAgent->id, 'tier_level' => 2, 'direct_commission_rate' => '8.00', 'status' => 'ACTIVE']);
        $directAgent = Agent::create(['id' => Str::uuid()->toString(), 'user_id' => $directUserId, 'agent_code' => 'D-SHOP', 'parent_agent_id' => $superAgent->id, 'tier_level' => 3, 'direct_commission_rate' => '5.00', 'status' => 'ACTIVE']);

        AgentHierarchy::create(['id' => Str::uuid()->toString(), 'ancestor_id' => $directAgent->id, 'descendant_id' => $directAgent->id, 'depth' => 0]);
        AgentHierarchy::create(['id' => Str::uuid()->toString(), 'ancestor_id' => $superAgent->id, 'descendant_id' => $directAgent->id, 'depth' => 1]);
        AgentHierarchy::create(['id' => Str::uuid()->toString(), 'ancestor_id' => $masterAgent->id, 'descendant_id' => $directAgent->id, 'depth' => 2]);

        CommissionRule::firstOrCreate(['game_type' => 'ALL', 'tier_level' => 1], ['id' => Str::uuid()->toString(), 'override_percentage' => '1.50', 'is_active' => true]);
        CommissionRule::firstOrCreate(['game_type' => 'ALL', 'tier_level' => 2], ['id' => Str::uuid()->toString(), 'override_percentage' => '1.00', 'is_active' => true]);

        // STEP 1: DEPOSIT 10,000 THB
        $depositService->processConfirmedDeposit(
            userId: $userId,
            amount: '10000.0000',
            gatewayProvider: 'PROMPTPAY',
            gatewayReferenceId: 'PROMETHEUS-PROOF-01',
            currency: 'THB'
        );
        $this->assertEquals('10000.0000', $userWallet->fresh()->balance);

        // STEP 2: BET PLACEMENT (1,000 THB ON '934521')
        $draw = Draw::create([
            'id' => Str::uuid()->toString(),
            'draw_code' => 'E2E-DRAW-001',
            'game_type' => 'THAI_6D',
            'draw_date' => now()->addHour(),
            'betting_starts_at' => now()->subHour(),
            'betting_ends_at' => now()->addMinutes(30),
            'status' => 'OPEN',
        ]);

        $entry = $orderService->placeOrder(
            userId: $userId,
            drawId: $draw->id,
            ticketNumber: '934521',
            betType: 'DIRECT_6D',
            stakeAmount: '1000.0000',
            agentId: $directAgent->id,
            idempotencyKey: 'ORDER-PROOF-001'
        );
        $this->assertEquals('9000.0000', $userWallet->fresh()->balance);

        // STEP 3: AGENT COMMISSION DISTRIBUTION
        $commissionService->distributeForEntry($entry->id);
        $this->assertEquals('50.0000', $directWallet->fresh()->balance); // 5%
        $this->assertEquals('10.0000', $superWallet->fresh()->balance);  // 1% override
        $this->assertEquals('15.0000', $masterWallet->fresh()->balance); // 1.5% override

        // STEP 4: 4-EYES RESULT INGESTION & CONFIRMATION
        $draw->status = 'CLOSED';
        $draw->save();

        $operator1 = Str::uuid()->toString();
        $operator2 = Str::uuid()->toString();

        $draft = $ingestionService->ingestPayload(
            drawId: $draw->id,
            resultPayload: ['first_prize' => '934521', 'two_digit_back' => '88'],
            operatorId: $operator1
        );
        $confirmed = $confirmationService->confirmResult(
            drawResultId: $draft->id,
            confirmingOperatorId: $operator2
        );
        $this->assertEquals('CONFIRMED', $confirmed->verification_status);

        // STEP 5: BATCH SETTLEMENT EVALUATION
        $chunkJob = new ChunkedSettlementJob($draw->id, 0, $confirmed->id);
        $chunkJob->handle($syncService);

        $entry->refresh();
        $this->assertEquals('WON', $entry->status);
        $this->assertEquals('60000000.0000', $entry->actual_win_amount);

        // STEP 6: IDEMPOTENT PAYOUT (60,000,000 THB)
        $payoutJob = new ProcessWinningEntryPayoutJob(
            lotteryEntryId: $entry->id,
            drawId: $draw->id,
            userId: $userId,
            winAmount: $entry->actual_win_amount,
            winningTier: 'FIRST_PRIZE'
        );
        $payoutJob->handle($walletMutationService);

        // Balance = 9,000 + 60,000,000 = 60,009,000.0000 THB
        $this->assertEquals('60009000.0000', $userWallet->fresh()->balance);

        // Replay payout execution (Idempotency assertion)
        $payoutJob->handle($walletMutationService);
        $this->assertEquals('60009000.0000', $userWallet->fresh()->balance);
        $this->assertEquals(1, Payout::where('draw_id', $draw->id)->count());

        // STEP 7: GENERAL LEDGER MATHEMATICAL INVARIANT RECONCILIATION
        $audit = $reconciliationService->auditSystemIntegrity();

        $this->assertEquals('HEALTHY', $audit['status']);
        $this->assertEquals('0.0000', $audit['ledger_balance_delta']);
        $this->assertEmpty($audit['wallet_discrepancies']);
        $this->assertEmpty($audit['tampered_transactions']);
    }
}
