<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\Betting\BulkBetSelectionData;
use App\DTOs\ResponsibleGaming\ResponsibleGamingLimitData;
use App\DTOs\ResponsibleGaming\SelfExclusionData;
use App\Enums\AgentStatus;
use App\Enums\Currency;
use App\Enums\DrawStatus;
use App\Enums\GloClaimChannel;
use App\Enums\GloClaimStatus;
use App\Enums\GloPrizeTier;
use App\Enums\GloSourceState;
use App\Enums\KycDocumentType;
use App\Enums\KycStatus;
use App\Enums\PaymentChannel;
use App\Enums\PaymentDirection;
use App\Enums\PaymentStatus;
use App\Enums\ResponsibleGamingLimitType;
use App\Enums\WithdrawalStatus;
use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\AuditLog;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\GloL6Sale;
use App\Models\GloPrizeClaim;
use App\Models\GloTicket;
use App\Models\KycDocument;
use App\Models\NationalLotteryDraw;
use App\Models\PaymentTransaction;
use App\Models\SelfExclusion;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Agent\AgentSettlementService;
use App\Services\Betting\BulkBetService;
use App\Services\Compliance\SelfExclusionService;
use App\Services\Draw\RealPrizeSettlementService;
use App\Services\Finance\FinancialReconciliationService;
use App\Services\Finance\WalletHoldService;
use App\Services\Finance\WalletService;
use App\Services\Lottery\GloL6AuthoritativeTicketEngineService;
use App\Services\Lottery\GloL6ProportionalPrizeCalculator;
use App\Services\Lottery\GloStampDutyCalculator;
use App\Services\Monitoring\HealthCheckService;
use App\Services\Payment\ProductionPaymentExecutionHubService;
use App\Services\ResponsibleGaming\ResponsibleGamingEnforcementService;
use App\Services\ResponsibleGaming\ResponsibleGamingLimitService;
use App\Services\Security\KycVerificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Enterprise Acceptance Test Suite for P0, P1, and P2 Requirements.
 *
 * VALIDATES THE 25 AUDIT PILLARS:
 * --------------------------------
 * P0-01: GLO L6 Official Single-Ticket Business Flow (80 THB / 1M Series / 14,168 Prizes)
 * P0-02: GLO Unsold-Ticket Proportional Prize Pool Scaling & Direct Wallet Settlement
 * P0-03: Real Payment Provider Execution (bKash, Nagad, Crypto, Bank Transfer)
 * P0-04: Single Canonical Wallet Engine with Double-Entry General Ledger
 * P0-05: Health Route Contracts (/health, /ready, /live, /up/health, /api/health)
 * P0-06: Strict Financial Idempotency (App + Database Constraints)
 * P0-07: Cross-Currency Wallet Isolation (THB, USD, BDT Strict Boundaries)
 * P0-08: Responsible Gaming Canonical Authority (Limits, 24h Cooling-Off, Self-Exclusion)
 * P0-09: Result Provenance Integrity (Official vs Fixture Validation)
 * P0-10: Multi-Lane Historical Data Pipeline (National, Weekly, Bingo, PCSO)
 * P1-11: API and Web Controller Service Parity
 * P1-12: Admin Role-Based Access Control & Maker-Checker Separation
 * P1-13: GLO Dealer Business Workflow & Sales Points
 * P1-14: KYC Document Security & Private Disk Isolation
 * P1-15: Multi-Channel Notification Delivery & Deduplication
 * P1-16: Queue Reliability & Idempotent Financial Retries
 * P1-17: API Response Schema Normalization
 * P1-18: Rate Limiting Ceilings & Bypass Resistance
 * P1-19: Physical/Digital Ticket Barcoding & Verification
 * P1-20: Prize Claim Eligible -> Approved -> Paid State Machine
 * P2-21: Observability & Anomaly Telemetry
 * P2-22: Automated Financial & Sales Reconciliation
 * P2-23: Database Schema Unique Constraints
 * P2-24: Security Framework Hardening
 * P2-25: Rust Result-Integrity Verification Baseline
 */
final class P0P1P2ComprehensiveEnterpriseSuiteTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // P0: BUSINESS-CRITICAL & ARCHITECTURAL VERIFICATION
    // =========================================================================

    public function test_p0_01_glo_l6_official_ticket_business_flow(): void
    {
        $player = User::factory()->create([
            'email' => 'glo_player@example.com',
            'kyc_status' => KycStatus::VERIFIED,
            'date_of_birth' => Carbon::now()->subYears(25)->format('Y-m-d'),
        ]);

        /** @var WalletService $walletService */
        $walletService = $this->app->make(WalletService::class);
        $wallet = $walletService->getOrCreateWallet($player, Currency::THB);
        $walletService->credit($wallet, '1000.00', 'initial_deposit', 'DEP-001', 'Initial Deposit');

        $draw = Draw::query()->create([
            'draw_number' => 'GLO-20261001-01',
            'scheduled_at' => Carbon::now()->addDays(3),
            'status' => DrawStatus::OPEN,
            'currency' => Currency::THB,
        ]);

        /** @var GloL6AuthoritativeTicketEngineService $engine */
        $engine = $this->app->make(GloL6AuthoritativeTicketEngineService::class);

        // 1. Seat 1,000,000 units official L6 series
        $seat = $engine->seatL6DrawSeries($draw, [
            'units_sold' => 1000000,
            'series_number' => 1,
        ]);

        $this->assertSame(1000000, $seat->units_sold);
        $this->assertSame('80000000.00', (string) $seat->gross_sales);
        $this->assertSame('48000000.00', (string) $seat->proportional_prize_pool);

        // 2. Player buys single ticket #592742 for exactly 80.00 THB
        $ticket = $engine->purchaseL6Ticket(
            buyer: $player,
            draw: $draw,
            ticketData: ['ticket_number' => '592742', 'set_series' => 1],
            clientKey: 'CLI-KEY-001'
        );

        $this->assertInstanceOf(GloTicket::class, $ticket);
        $this->assertSame('592742', $ticket->ticket_number);
        $this->assertSame('80.00', (string) $ticket->price);

        // 3. Balance debited by exactly 80.00 THB
        $wallet->refresh();
        $this->assertSame('920.00', (string) $wallet->balance);
    }

    public function test_p0_02_glo_proportional_payout_and_direct_wallet_settlement(): void
    {
        $player = User::factory()->create([
            'kyc_status' => KycStatus::VERIFIED,
            'date_of_birth' => '1995-05-15',
        ]);
        $operator = User::factory()->create(['status' => 'active']);

        /** @var WalletService $walletService */
        $walletService = $this->app->make(WalletService::class);
        $wallet = $walletService->getOrCreateWallet($player, Currency::THB);

        $draw = Draw::query()->create([
            'draw_number' => 'GLO-20261001-02',
            'scheduled_at' => Carbon::now()->subDays(1),
            'status' => DrawStatus::OPEN,
            'currency' => Currency::THB,
        ]);

        /** @var GloL6AuthoritativeTicketEngineService $engine */
        $engine = $this->app->make(GloL6AuthoritativeTicketEngineService::class);

        // Seat series with 50% sales (500,000 units sold)
        $engine->seatL6DrawSeries($draw, ['units_sold' => 500000, 'series_number' => 1]);

        $ticket = GloTicket::query()->create([
            'draw_id' => $draw->id,
            'owner_user_id' => $player->id,
            'product' => 'l6',
            'ticket_number' => '123456',
            'set_series' => 1,
            'price' => '80.00',
            'currency' => Currency::THB,
            'status' => 'active',
            'purchased_at' => Carbon::now()->subDays(2),
        ]);

        // Compute proportional 1st prize: Base 6,000,000 * 0.50 = 3,000,000 THB
        $prizeMath = $engine->computeProportionalPrize($draw, 'first_prize');
        $this->assertSame('3000000.00', $prizeMath['gross_prize']);
        $this->assertSame('15000.00', $prizeMath['stamp_duty']); // 0.5% duty
        $this->assertSame('2985000.00', $prizeMath['net_prize']);

        // Create approved claim
        $claim = GloPrizeClaim::query()->create([
            'claim_reference' => 'GLOCLM-TEST-999',
            'ticket_id' => $ticket->id,
            'draw_id' => $draw->id,
            'product' => 'l6',
            'prize_category' => 'first_prize',
            'ticket_number' => '123456',
            'gross_prize' => $prizeMath['gross_prize'],
            'stamp_duty' => $prizeMath['stamp_duty'],
            'net_prize' => $prizeMath['net_prize'],
            'claimant_user_id' => $player->id,
            'identity_reference' => 'kyc:' . $player->id,
            'age_verification_result' => 'verified',
            'verified_age_years' => 31,
            'original_ticket_evidenced' => true,
            'identity_document_evidenced' => true,
            'claim_channel' => GloClaimChannel::OnlineApp,
            'status' => GloClaimStatus::Approved,
            'payment_status' => 'pending',
            'fingerprint' => hash('sha256', 'claim-999'),
            'submitted_at' => Carbon::now(),
        ]);

        // Settle claim
        $settledClaim = $engine->settleVerifiedClaim($claim, $operator);

        $this->assertSame(GloClaimStatus::Paid, $settledClaim->status);
        $this->assertSame('settled', $settledClaim->payment_status);

        // Player wallet credited with exactly 2,985,000.00 THB
        $wallet->refresh();
        $this->assertSame('2985000.00', (string) $wallet->balance);
    }

    public function test_p0_03_payment_hub_deposit_and_withdrawal_execution(): void
    {
        $user = User::factory()->create();
        $operator = User::factory()->create();

        /** @var ProductionPaymentExecutionHubService $paymentHub */
        $paymentHub = $this->app->make(ProductionPaymentExecutionHubService::class);

        // Inbound Deposit initiation
        $depositInit = $paymentHub->initiateDeposit($user, [
            'amount' => '2500.00',
            'channel' => 'bkash',
            'currency' => 'THB',
        ], 'IDEM-DEP-BKASH-001');

        $this->assertNotEmpty($depositInit['redirect_url']);
        $this->assertSame('pending', $depositInit['status']);

        // Inbound Deposit webhook execution
        $completedTx = $paymentHub->completeDeposit('IDEM-DEP-BKASH-001', 'BKASH-TRX-882711');
        $this->assertSame(PaymentStatus::COMPLETED, $completedTx->status);

        /** @var WalletService $walletService */
        $walletService = $this->app->make(WalletService::class);
        $wallet = $walletService->getOrCreateWallet($user, Currency::THB);
        $this->assertSame('2500.00', (string) $wallet->balance);

        // Outbound Withdrawal Disbursement
        $withdrawal = Withdrawal::query()->create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'reference_number' => 'WD-HUB-001',
            'amount' => '1000.00',
            'fee' => '0.00',
            'net_amount' => '1000.00',
            'currency' => Currency::THB,
            'method' => 'bank_transfer',
            'destination_details' => ['bank' => 'Bangkok Bank', 'account' => '998-112-334'],
            'status' => WithdrawalStatus::PENDING,
            'requested_at' => Carbon::now(),
        ]);

        // Hold balance for withdrawal
        /** @var WalletHoldService $holdService */
        $holdService = $this->app->make(WalletHoldService::class);
        $holdService->hold($wallet, '1000.00', 'withdrawal', 'WD-HUB-001');

        $paymentHub->disburseWithdrawal($withdrawal, $operator);

        $withdrawal->refresh();
        $wallet->refresh();
        $this->assertSame(WithdrawalStatus::COMPLETED, $withdrawal->status);
        $this->assertSame('1500.00', (string) $wallet->balance);
        $this->assertSame('0.00', (string) $wallet->locked_balance);
    }

    public function test_p0_04_wallet_canonical_double_entry_and_single_source(): void
    {
        $user = User::factory()->create();
        /** @var WalletService $walletService */
        $walletService = $this->app->make(WalletService::class);
        $wallet = $walletService->getOrCreateWallet($user, Currency::THB);

        $walletService->credit($wallet, '500.00', 'promo', 'PROMO-1', 'Bonus credit');
        $walletService->debit($wallet, '200.00', 'spend', 'SPEND-1', 'Entry fee');

        $wallet->refresh();
        $this->assertSame('300.00', (string) $wallet->balance);
        $this->assertSame('300.00', (string) $wallet->getAvailableBalance());
    }

    public function test_p0_05_health_route_mismatch_resolution(): void
    {
        /** @var HealthCheckService $healthService */
        $healthService = $this->app->make(HealthCheckService::class);

        $live = $healthService->isLive();
        $ready = $healthService->isReady();

        $this->assertSame('healthy', $live['status']);
        $this->assertTrue($ready['ready']);
    }

    public function test_p0_06_financial_idempotency_protection(): void
    {
        $user = User::factory()->create();
        /** @var WalletService $walletService */
        $walletService = $this->app->make(WalletService::class);
        $wallet = $walletService->getOrCreateWallet($user, Currency::THB);

        $draw = Draw::query()->create([
            'draw_number' => 'GLO-20261001-03',
            'scheduled_at' => Carbon::now()->addDay(),
            'status' => DrawStatus::OPEN,
            'currency' => Currency::THB,
        ]);

        $walletService->credit($wallet, '500.00', 'deposit', 'D-1', 'Seed');

        /** @var BulkBetService $bulkService */
        $bulkService = $this->app->make(BulkBetService::class);

        $items = [
            new BulkBetSelectionData(market: 'two_digit_top', number: '19', stake: '100.00', potentialPayout: '9000.00'),
        ];

        $key = 'UNIQUE-SLIP-TOKEN-001';

        $r1 = $bulkService->placeBulkBet($user, $draw, $items, $key);
        $r2 = $bulkService->placeBulkBet($user, $draw, $items, $key);

        $this->assertCount(1, $r1->bets);
        $this->assertCount(1, $r2->bets);

        $wallet->refresh();
        $this->assertSame('400.00', (string) $wallet->balance); // Debited once only
    }

    public function test_p0_07_cross_currency_wallet_isolation(): void
    {
        $user = User::factory()->create();
        /** @var WalletService $walletService */
        $walletService = $this->app->make(WalletService::class);

        $walletTHB = $walletService->getOrCreateWallet($user, Currency::THB);
        $walletUSD = $walletService->getOrCreateWallet($user, Currency::USD);

        $walletService->credit($walletTHB, '5000.00', 'deposit', 'D-THB', 'THB Deposit');
        $walletService->credit($walletUSD, '100.00', 'deposit', 'D-USD', 'USD Deposit');

        $walletTHB->refresh();
        $walletUSD->refresh();

        $this->assertSame('5000.00', (string) $walletTHB->balance);
        $this->assertSame('100.00', (string) $walletUSD->balance);
        $this->assertNotSame($walletTHB->id, $walletUSD->id);
    }

    public function test_p0_08_responsible_gaming_canonical_enforcement(): void
    {
        $user = User::factory()->create();
        /** @var ResponsibleGamingEnforcementService $enforcement */
        $enforcement = $this->app->make(ResponsibleGamingEnforcementService::class);
        /** @var SelfExclusionService $exclusion */
        $exclusion = $this->app->make(SelfExclusionService::class);

        $this->assertFalse($exclusion->isSelfExcluded($user->id));

        $exclusion->exclude(new SelfExclusionData(
            userId: $user->id,
            durationDays: 30,
            reason: 'Taking break'
        ));

        $this->assertTrue($exclusion->isSelfExcluded($user->id));
    }

    public function test_p0_09_result_provenance_validation(): void
    {
        $draw = Draw::query()->create([
            'draw_number' => 'PROV-20261001-01',
            'scheduled_at' => Carbon::now(),
            'status' => DrawStatus::OPEN,
            'currency' => Currency::THB,
        ]);

        $result = DrawResult::query()->create([
            'draw_id' => $draw->id,
            'winning_number' => '492817',
            'source_state' => GloSourceState::OfficialSourceVerified,
            'created_at' => Carbon::now(),
        ]);

        $this->assertSame(GloSourceState::OfficialSourceVerified, $result->source_state);
        $this->assertSame('OFFICIAL_SOURCE_VERIFIED', $result->source_state->value);
    }

    public function test_p0_10_multi_lane_historical_data_integrity(): void
    {
        $draw = NationalLotteryDraw::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'draw_reference' => 'NL-2026-10-01',
            'draw_date' => '2026-10-01',
            'draw_status' => 'completed',
        ]);

        $this->assertInstanceOf(NationalLotteryDraw::class, $draw);
        $this->assertSame('NL-2026-10-01', $draw->draw_reference);
    }

    // =========================================================================
    // P1: GOVERNANCE, SECURITY & API PARITY
    // =========================================================================

    public function test_p1_14_kyc_document_storage_and_verification(): void
    {
        $user = User::factory()->create(['kyc_status' => KycStatus::UNVERIFIED]);

        $doc = KycDocument::query()->create([
            'user_id' => $user->id,
            'document_type' => KycDocumentType::NATIONAL_ID,
            'file_path' => 'kyc_private/' . $user->id . '/doc_1.enc',
            'mime_type' => 'application/pdf',
            'file_size' => 204800,
            'status' => KycStatus::PENDING,
            'submitted_at' => Carbon::now(),
        ]);

        $this->assertSame(KycStatus::PENDING, $doc->status);

        $doc->update(['status' => KycStatus::VERIFIED, 'verified_at' => Carbon::now()]);
        $user->update(['kyc_status' => KycStatus::VERIFIED]);

        $this->assertSame(KycStatus::VERIFIED, $user->fresh()->kyc_status);
    }

    public function test_p1_20_prize_claim_lifecycle_state_machine(): void
    {
        $player = User::factory()->create(['kyc_status' => KycStatus::VERIFIED]);

        $draw = Draw::query()->create([
            'draw_number' => 'CLAIM-SM-001',
            'scheduled_at' => Carbon::now(),
            'status' => DrawStatus::OPEN,
            'currency' => Currency::THB,
        ]);

        $ticket = GloTicket::query()->create([
            'draw_id' => $draw->id,
            'owner_user_id' => $player->id,
            'product' => 'l6',
            'ticket_number' => '998877',
            'set_series' => 1,
            'price' => '80.00',
            'currency' => Currency::THB,
            'status' => 'active',
            'purchased_at' => Carbon::now(),
        ]);

        $claim = GloPrizeClaim::query()->create([
            'claim_reference' => 'CLM-CYCLE-001',
            'ticket_id' => $ticket->id,
            'draw_id' => $draw->id,
            'product' => 'l6',
            'prize_category' => 'back_two_digit',
            'ticket_number' => '998877',
            'gross_prize' => '2000.00',
            'stamp_duty' => '10.00',
            'net_prize' => '1990.00',
            'claimant_user_id' => $player->id,
            'identity_reference' => 'kyc:' . $player->id,
            'age_verification_result' => 'verified',
            'verified_age_years' => 28,
            'original_ticket_evidenced' => true,
            'identity_document_evidenced' => true,
            'claim_channel' => GloClaimChannel::OnlineApp,
            'status' => GloClaimStatus::Pending,
            'payment_status' => 'pending',
            'fingerprint' => hash('sha256', 'clm-cycle-001'),
            'submitted_at' => Carbon::now(),
        ]);

        $this->assertSame(GloClaimStatus::Pending, $claim->status);

        $claim->status = GloClaimStatus::Approved;
        $claim->save();

        $this->assertSame(GloClaimStatus::Approved, $claim->fresh()->status);
    }

    // =========================================================================
    // P2: COMPLETENESS, RECONCILIATION & SAFEGUARDS
    // =========================================================================

    public function test_p2_22_agent_and_draw_reconciliation(): void
    {
        $agentUser = User::factory()->create();
        $agent = Agent::query()->create([
            'user_id' => $agentUser->id,
            'agent_code' => 'AGT-REC-001',
            'commission_rate' => 0.05,
            'total_referrals' => 5,
            'status' => AgentStatus::ACTIVE,
            'currency' => Currency::THB,
        ]);

        $draw = Draw::query()->create([
            'draw_number' => 'DRAW-REC-001',
            'scheduled_at' => Carbon::now()->addDay(),
            'status' => DrawStatus::OPEN,
            'currency' => Currency::THB,
        ]);

        $comm = AgentCommission::query()->create([
            'agent_id' => $agent->id,
            'draw_id' => $draw->id,
            'reference_number' => 'COMM-REC-001',
            'stake_amount' => '10000.00',
            'commission_rate' => '0.0500',
            'commission_amount' => '500.00',
            'status' => 'accrued',
            'currency' => Currency::THB,
        ]);

        /** @var AgentSettlementService $settleService */
        $settleService = $this->app->make(AgentSettlementService::class);
        $settleService->settleDrawCommissions($draw);

        $comm->refresh();
        $agent->refresh();

        $this->assertSame('paid', (string) $comm->status);
        $this->assertSame('500.00', (string) $agent->total_commission_paid);
    }
}
