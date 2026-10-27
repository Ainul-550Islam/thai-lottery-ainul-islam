<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\WalletStatus;
use App\Jobs\Settlement\ChunkedSettlementJob;
use App\Jobs\Settlement\ProcessWinningEntryPayoutJob;
use App\Models\Agent;
use App\Models\AgentHierarchy;
use App\Models\CommissionRule;
use App\Models\Draw;
use App\Models\Payout;
use App\Models\User;
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

        // 1. Initial State: Setup 3-tier Agent Structure + Bettor with canonical User FKs
        $user = User::factory()->create();
        $masterUser = User::factory()->create();
        $superUser = User::factory()->create();
        $directUser = User::factory()->create();

        $userWallet = new Wallet(['user_id' => $user->id, 'type' => 'main', 'currency' => 'THB']);
        $userWallet->status = WalletStatus::Active;
        $userWallet->save();

        $masterWallet = new Wallet(['user_id' => $masterUser->id, 'type' => 'commission', 'currency' => 'THB']);
        $masterWallet->status = WalletStatus::Active;
        $masterWallet->save();

        $superWallet = new Wallet(['user_id' => $superUser->id, 'type' => 'commission', 'currency' => 'THB']);
        $superWallet->status = WalletStatus::Active;
        $superWallet->save();

        $directWallet = new Wallet(['user_id' => $directUser->id, 'type' => 'commission', 'currency' => 'THB']);
        $directWallet->status = WalletStatus::Active;
        $directWallet->save();

        $masterAgent = Agent::create(['user_id' => $masterUser->id, 'agent_code' => 'M-HQ', 'tier_level' => 1, 'direct_commission_rate' => '10.00', 'status' => 'ACTIVE']);
        $superAgent = Agent::create(['user_id' => $superUser->id, 'agent_code' => 'S-BKK', 'parent_agent_id' => $masterAgent->id, 'tier_level' => 2, 'direct_commission_rate' => '8.00', 'status' => 'ACTIVE']);
        $directAgent = Agent::create(['user_id' => $directUser->id, 'agent_code' => 'D-SHOP', 'parent_agent_id' => $superAgent->id, 'tier_level' => 3, 'direct_commission_rate' => '5.00', 'status' => 'ACTIVE']);

        AgentHierarchy::create(['ancestor_id' => $directAgent->id, 'descendant_id' => $directAgent->id, 'depth' => 0]);
        AgentHierarchy::create(['ancestor_id' => $superAgent->id, 'descendant_id' => $directAgent->id, 'depth' => 1]);
        AgentHierarchy::create(['ancestor_id' => $masterAgent->id, 'descendant_id' => $directAgent->id, 'depth' => 2]);

        CommissionRule::firstOrCreate(['game_type' => 'ALL', 'tier_level' => 1], ['override_percentage' => '1.50', 'is_active' => true]);
        CommissionRule::firstOrCreate(['game_type' => 'ALL', 'tier_level' => 2], ['override_percentage' => '1.00', 'is_active' => true]);

        // STEP 1: DEPOSIT 10,000 THB
        $depositService->processConfirmedDeposit(
            userId: $user->id,
            amount: '10000.0000',
            gatewayProvider: 'PROMPTPAY',
            gatewayReferenceId: 'PROMETHEUS-PROOF-01',
            currency: 'THB'
        );
        $this->assertEquals('10000.0000', bcadd((string) $userWallet->fresh()->balance, '0', 4));

        // STEP 2: BET PLACEMENT (1,000 THB ON '934521')
        $draw = Draw::create([
            'draw_code' => 'E2E-DRAW-001',
            'game_type' => 'THAI_6D',
            'draw_date' => now()->addHour(),
            'betting_starts_at' => now()->subHour(),
            'betting_ends_at' => now()->addMinutes(30),
            'status' => 'OPEN',
        ]);

        $entry = $orderService->placeOrder(
            userId: $user->id,
            drawId: $draw->id,
            ticketNumber: '934521',
            betType: 'DIRECT_6D',
            stakeAmount: '1000.0000',
            agentId: $directAgent->id,
            idempotencyKey: 'ORDER-PROOF-001'
        );
        $this->assertEquals('9000.0000', bcadd((string) $userWallet->fresh()->balance, '0', 4));

        // STEP 3: AGENT COMMISSION DISTRIBUTION
        $commissionService->distributeForEntry($entry->id);
        $this->assertEquals('50.0000', bcadd((string) $directWallet->fresh()->balance, '0', 4)); // 5%
        $this->assertEquals('10.0000', bcadd((string) $superWallet->fresh()->balance, '0', 4));  // 1% override
        $this->assertEquals('15.0000', bcadd((string) $masterWallet->fresh()->balance, '0', 4)); // 1.5% override

        // STEP 4: 4-EYES RESULT INGESTION & CONFIRMATION
        $draw->status = 'CLOSED';
        $draw->save();

        $operator1 = User::factory()->create();
        $operator2 = User::factory()->create();

        $draft = $ingestionService->ingestPayload(
            drawId: $draw->id,
            resultPayload: ['first_prize' => '934521', 'two_digit_back' => '88'],
            operatorId: $operator1->id
        );
        $confirmed = $confirmationService->confirmResult(
            drawResultId: $draft->id,
            confirmingOperatorId: $operator2->id
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
            userId: $user->id,
            winAmount: $entry->actual_win_amount,
            winningTier: 'FIRST_PRIZE'
        );
        $payoutJob->handle($walletMutationService);

        // Balance = 9,000 + 60,000,000 = 60,009,000.0000 THB
        $this->assertEquals('60009000.0000', bcadd((string) $userWallet->fresh()->balance, '0', 4));

        // Replay payout execution (Idempotency assertion)
        $payoutJob->handle($walletMutationService);
        $this->assertEquals('60009000.0000', bcadd((string) $userWallet->fresh()->balance, '0', 4));
        $this->assertEquals(1, Payout::where('draw_id', $draw->id)->count());

        // STEP 7: GENERAL LEDGER MATHEMATICAL INVARIANT RECONCILIATION
        $audit = $reconciliationService->auditSystemIntegrity();

        $this->assertEquals('HEALTHY', $audit['status']);
        $this->assertEquals('0.0000', $audit['ledger_balance_delta']);
        $this->assertEmpty($audit['wallet_discrepancies']);
        $this->assertEmpty($audit['tampered_transactions']);
    }
}
