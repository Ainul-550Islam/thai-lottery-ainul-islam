<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\Betting\BulkBetSelectionData;
use App\DTOs\ResponsibleGaming\SelfExclusionData;
use App\Enums\AgentStatus;
use App\Enums\Currency;
use App\Enums\DrawStatus;
use App\Enums\GloPrizeTier;
use App\Enums\GloSourceState;
use App\Enums\KycDocumentType;
use App\Enums\KycStatus;
use App\Enums\PaymentChannel;
use App\Enums\PaymentDirection;
use App\Enums\PaymentStatus;
use App\Enums\TransactionStatus;
use App\Enums\WithdrawalStatus;
use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\KycDocument;
use App\Models\PaymentTransaction;
use App\Models\SelfExclusion;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Agent\AgentSettlementService;
use App\Services\Betting\BulkBetService;
use App\Services\Compliance\SelfExclusionService;
use App\Services\Finance\FinancialReconciliationService;
use App\Services\Finance\WalletReservationService;
use App\Services\Finance\WalletService;
use App\Services\Lottery\GloL6ProportionalPrizeCalculator;
use App\Services\Monitoring\HealthCheckService;
use App\Services\Payment\WithdrawalDisbursementService;
use App\Services\ResponsibleGaming\ResponsibleGamingLimitService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Whole-System Master Integration Test Suite (Prompt 7 Acceptance Gate).
 *
 * Verifies end-to-end integration across all platform modules:
 * Authentication, Wallets, Deposits, Draws, Bets, Claims, Disbursements,
 * Agent Settlements, Compliance, KYC, and System Observability.
 */
final class FinalWholeSystemNoSkipTest extends TestCase
{
    // DatabaseTruncation, NOT RefreshDatabase: the bet-purchase pipeline
    // refuses to run inside somebody else's transaction, and
    // RefreshDatabase wraps every test in one.
    use DatabaseTruncation;

    public function test_01_user_registration_and_wallet_initialization(): void
    {
        $user = User::factory()->create([
            'email' => 'player_master@example.com',
            'phone' => '+66890000001',
            'status' => 'active',
            'kyc_status' => KycStatus::UNVERIFIED,
        ]);

        /** @var WalletService $walletService */
        $walletService = $this->app->make(WalletService::class);
        $wallet = $walletService->getOrCreateWallet($user, Currency::THB);

        $this->assertInstanceOf(Wallet::class, $wallet);
        $this->assertSame('0.00', (string) $wallet->balance);
        $this->assertSame('0.00', (string) $wallet->locked_balance);
        $this->assertTrue($wallet->canTransact());
    }

    public function test_02_deposit_funding_and_balance_crediting(): void
    {
        $user = User::factory()->create();
        /** @var WalletService $walletService */
        $walletService = $this->app->make(WalletService::class);
        $wallet = $walletService->getOrCreateWallet($user, Currency::THB);

        $tx = PaymentTransaction::query()->create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'reference_id' => 'TX-DEP-' . bin2hex(random_bytes(4)),
            'provider' => 'promptpay',
            'channel' => PaymentChannel::PROMPTPAY,
            'direction' => PaymentDirection::INBOUND,
            'amount' => '5000.00',
            'fee' => '0.00',
            'currency' => Currency::THB,
            'status' => PaymentStatus::COMPLETED,
            'processed_at' => Carbon::now(),
        ]);

        $walletService->credit(
            wallet: $wallet,
            amount: '5000.00',
            referenceType: 'payment_transaction',
            referenceId: (string) $tx->id,
            description: 'PromptPay Wallet Deposit'
        );

        $wallet->refresh();
        $this->assertSame('5000.00', (string) $wallet->balance);
        $this->assertSame('5000.00', (string) $wallet->getAvailableBalance());
    }

    public function test_03_draw_creation_and_lifecycle(): void
    {
        $draw = Draw::query()->create([
            'draw_number' => 'GLO-20260930-01',
            'scheduled_at' => Carbon::now()->addDays(2),
            'status' => DrawStatus::OPEN,
            'currency' => Currency::THB,
        ]);

        $this->assertSame(DrawStatus::OPEN, $draw->status);
        $this->assertTrue($draw->isOpen());

        $draw->status = DrawStatus::CLOSED;
        $draw->save();

        $this->assertFalse($draw->fresh()->isOpen());
    }

    public function test_04_bulk_bet_placement_with_idempotency_and_satang_math(): void
    {
        $user = User::factory()->create();
        /** @var WalletService $walletService */
        $walletService = $this->app->make(WalletService::class);
        $wallet = $walletService->getOrCreateWallet($user, Currency::THB);
        $walletService->credit($wallet, '1000.00', 'seed', '1', 'Initial balance');

        $draw = Draw::query()->create([
            'draw_number' => 'GLO-20260930-02',
            'scheduled_at' => Carbon::now()->addDay(),
            'status' => DrawStatus::OPEN,
            'currency' => Currency::THB,
        ]);

        /** @var BulkBetService $betService */
        $betService = $this->app->make(BulkBetService::class);

        // Draws acquire per-number capacity rows through the canonical
        // provisioning service; a bare draw cannot reserve against limits
        // that do not exist.
        $this->app->make(\App\Services\Risk\NumberLimitProvisioningService::class)->provision($draw);

        $items = [
            // Canonical market keys (config/lottery.php): 'two_digit_top' /
            // 'three_digit_top' name the same 2d_top / 3d_direct markets in
            // the platform's own vocabulary.
            new BulkBetSelectionData(
                market: '2d_top',
                number: '42',
                stake: '50.00',
                potentialPayout: '4500.00'
            ),
            new BulkBetSelectionData(
                market: '3d_direct',
                number: '742',
                stake: '100.00',
                potentialPayout: '90000.00'
            ),
        ];

        $clientKey = 'IDEM-BET-' . bin2hex(random_bytes(6));

        $result = $betService->placeBulkBet(
            user: $user,
            draw: $draw,
            selections: $items,
            clientKey: $clientKey
        );

        $this->assertNotEmpty($result->bets);
        $this->assertCount(2, $result->bets);
        $this->assertSame('150.00', (string) $result->totalStake);

        $wallet->refresh();
        $this->assertSame('850.00', (string) $wallet->balance);

        // Idempotency: replay the same request and ensure no duplicate debit
        $replay = $betService->placeBulkBet(
            user: $user,
            draw: $draw,
            selections: $items,
            clientKey: $clientKey
        );

        $this->assertCount(2, $replay->bets);
        $wallet->refresh();
        $this->assertSame('850.00', (string) $wallet->balance);
    }

    public function test_05_proportional_prize_calculation_and_settlement(): void
    {
        /** @var GloL6ProportionalPrizeCalculator $calc */
        $calc = $this->app->make(GloL6ProportionalPrizeCalculator::class);

        $results = $calc->calculatePrizePools(
            totalRevenue: '10000000.00',
            prizePoolAllocationPercent: '60.00'
        );

        $this->assertIsArray($results);
        $this->assertArrayHasKey('first_prize_pool', $results);
        $this->assertArrayHasKey('stamp_duty_total', $results);
    }

    public function test_06_withdrawal_request_and_disbursement_lifecycle(): void
    {
        $user = User::factory()->create();
        /** @var WalletService $walletService */
        $walletService = $this->app->make(WalletService::class);
        $wallet = $walletService->getOrCreateWallet($user, Currency::THB);
        $walletService->credit($wallet, '3000.00', 'initial', '1', 'Initial balance');

        /** @var WalletReservationService $resService */
        $resService = $this->app->make(WalletReservationService::class);
        // The reservation is seated by hand here (the approval lane's own
        // step), so the withdrawal row below is seated APPROVED to pair
        // with it: a Pending row would make disburse() approve — and
        // approve() seats a SECOND reservation — while completion consumes
        // exactly one.
        $res = $resService->reserve($wallet, '1000.00', 'withdrawal', 'WD-TEST-001');

        // `requested_at` is not mass-assignable on Withdrawal (the service
        // layer writes lifecycle timestamps), so the hand-made row is seated
        // with forceFill rather than widening the model's guard.
        $withdrawal = (new Withdrawal())->forceFill([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'reference_number' => 'WD-TEST-001',
            'amount' => '1000.00',
            'fee' => '0.00',
            'net_amount' => '1000.00',
            'currency' => Currency::THB,
            'method' => 'bank_transfer',
            'destination_details' => ['bank_name' => 'SCB', 'account_number' => '1234567890'],
            'status' => WithdrawalStatus::Approved,
            'requested_at' => Carbon::now(),
        ]);
        $withdrawal->save();

        $wallet->refresh();
        $this->assertSame('3000.00', (string) $wallet->balance);
        $this->assertSame('1000.00', (string) $wallet->locked_balance);
        $this->assertSame('2000.00', (string) $wallet->getAvailableBalance());

        // bank_transfer payout is an opt-in rail (config/payment.php), and
        // the disbursement lane refuses a still-PENDING withdrawal while
        // manual approval is required — switched off for this automated run.
        config([
            'payment.gateways.bank_transfer.enabled' => true,
            'finance.withdrawal.require_manual_approval' => false,
        ]);

        /** @var WithdrawalDisbursementService $disbService */
        $disbService = $this->app->make(WithdrawalDisbursementService::class);
        $disbService->disburse($withdrawal);

        // bank_transfer is the manual-wire lane: disburse() dispatches the
        // instruction and leaves the withdrawal Processing, awaiting the
        // payout confirmation. The confirmation step is the canonical
        // completion service — the same verb the withdrawal-success
        // webhook calls — driven explicitly here.
        $this->app->make(\App\Services\Finance\WithdrawalCompletionService::class)
            ->complete($withdrawal->fresh());

        $withdrawal->refresh();
        $wallet->refresh();

        $this->assertSame(WithdrawalStatus::Completed, $withdrawal->status);
        $this->assertSame('2000.00', (string) $wallet->balance);
        $this->assertSame('0.00', (string) $wallet->locked_balance);
    }

    public function test_07_agent_referral_and_settlement_journal(): void
    {
        $agentUser = User::factory()->create();
        $agent = Agent::query()->create([
            'user_id' => $agentUser->id,
            'agent_code' => 'AGT-' . bin2hex(random_bytes(3)),
            'commission_rate' => 0.05,
            'total_referrals' => 1,
            'status' => AgentStatus::ACTIVE,
            'currency' => Currency::THB,
        ]);

        $draw = Draw::query()->create([
            'draw_number' => 'GLO-20260930-03',
            'scheduled_at' => Carbon::now()->addDay(),
            'status' => DrawStatus::OPEN,
            'currency' => Currency::THB,
        ]);

        $comm = AgentCommission::query()->create([
            'agent_id' => $agent->id,
            'draw_id' => $draw->id,
            'reference_number' => 'COMM-' . bin2hex(random_bytes(4)),
            'stake_amount' => '1000.00',
            'commission_rate' => '0.0500',
            'commission_amount' => '50.00',
            'status' => 'accrued',
            'currency' => Currency::THB,
        ]);

        // The settlement engine credits the agent's canonical THB wallet
        // (created the platform way); the original setup never created one.
        $this->app->make(WalletService::class)->getOrCreateWallet($agentUser, Currency::THB);

        /** @var AgentSettlementService $settleService */
        $settleService = $this->app->make(AgentSettlementService::class);
        $settleService->settleDrawCommissions($draw);

        $comm->refresh();
        $agent->refresh();

        // `status` is enum-cast (CommissionStatus): compare the stored VALUE.
        $this->assertSame('paid', $comm->status->value);
        $this->assertSame('50.00', (string) $agent->total_commission_paid);
    }

    public function test_08_responsible_gaming_and_self_exclusion_safeguards(): void
    {
        $user = User::factory()->create();

        /** @var SelfExclusionService $exclusionService */
        $exclusionService = $this->app->make(SelfExclusionService::class);

        $this->assertFalse($exclusionService->isSelfExcluded($user->id));

        $exclusionService->exclude(new SelfExclusionData(
            userId: $user->id,
            durationDays: 30,
            reason: 'Take a break'
        ));

        $this->assertTrue($exclusionService->isSelfExcluded($user->id));
    }

    public function test_09_kyc_document_submission_and_lifecycle(): void
    {
        $user = User::factory()->create(['kyc_status' => KycStatus::UNVERIFIED]);

        $doc = KycDocument::query()->create([
            'user_id' => $user->id,
            'document_type' => KycDocumentType::NATIONAL_ID,
            'file_path' => 'kyc_private/id_test_01.enc',
            'mime_type' => 'application/pdf',
            'file_size' => 102400,
            'status' => KycStatus::PENDING,
            'submitted_at' => Carbon::now(),
        ]);

        $this->assertSame(KycStatus::PENDING, $doc->status);

        $doc->status = KycStatus::VERIFIED;
        $doc->verified_at = Carbon::now();
        $doc->save();

        $user->kyc_status = KycStatus::VERIFIED;
        $user->save();

        $this->assertSame(KycStatus::VERIFIED, $user->fresh()->kyc_status);
    }

    public function test_10_system_observability_and_health_integrity(): void
    {
        /** @var HealthCheckService $healthService */
        $healthService = $this->app->make(HealthCheckService::class);

        $live = $healthService->isLive();
        $ready = $healthService->isReady();
        $report = $healthService->checkHealth();

        $this->assertSame('healthy', $live['status']);
        $this->assertTrue($ready['ready']);
        $this->assertIsArray($report['dependencies']);
    }
}
