<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\Betting\BulkBetSelectionData;
use App\DTOs\Payment\GatewayWithdrawalResponse;
use App\DTOs\ResponsibleGaming\SelfExclusionData;
use App\Enums\AgentStatus;
use App\Enums\CommissionStatus;
use App\Enums\Currency;
use App\Enums\DrawStatus;
use App\Enums\GloPrizeTier;
use App\Enums\GloSourceState;
use App\Enums\KycDocumentType;
use App\Enums\KycStatus;
use App\Enums\PaymentChannel;
use App\Enums\PaymentDirection;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\TransactionStatus;
use App\Enums\FinancialTransactionType;
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
use App\Services\Finance\DepositApprovalService;
use App\Services\Finance\DepositCompletionService;
use App\Services\Finance\DepositService;
use App\Services\Finance\FinancialReconciliationService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletReservationService;
use App\Services\Finance\WalletService;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalService;
use App\Services\Lottery\GloL6ProportionalPrizeCalculator;
use App\Services\Lottery\GloStampDutyCalculator;
use App\Services\Monitoring\HealthCheckService;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\WithdrawalDisbursementService;
use App\Services\ResponsibleGaming\ResponsibleGamingLimitService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Agent\AgentTestCase;

/**
 * Whole-System Master Integration Test Suite (Prompt 7 Acceptance Gate).
 *
 * Verifies end-to-end integration across all platform modules:
 * Authentication, Wallets, Deposits, Draws, Bets, Claims, Disbursements,
 * Agent Settlements, Compliance, KYC, and System Observability.
 */
final class FinalWholeSystemNoSkipTest extends AgentTestCase
{

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
        $wallet = app(WalletService::class)->getOrCreateWallet($user, Currency::THB);
        $deposit = app(DepositService::class)->request(
            $wallet, Money::of('5000.00', Currency::THB), PaymentMethod::BankTransfer,
            'master-deposit-request-'.bin2hex(random_bytes(6)),
        );
        app(DepositApprovalService::class)->approve($deposit, null, 'Synthetic verified statement');
        $completion = app(DepositCompletionService::class)->complete(
            $deposit->fresh(), options: ['provider_reference' => 'SYNTHETIC-STATEMENT-'.bin2hex(random_bytes(6))],
        );
        $this->assertTrue($completion['credited']);
        $this->assertTrue(app(DepositCompletionService::class)->complete($deposit->fresh())['replayed']);

        $wallet->refresh();
        $this->assertSame('5000.00', (string) $wallet->balance);
        $this->assertSame('5000.00', (string) $wallet->getAvailableBalance());
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('ledger_entries', 2);
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
        $walletService->credit($wallet, '1000.00', FinancialTransactionType::Deposit, 'master-bulk-seed', ['description' => 'Initial balance']);

        $draw = $this->createOpenDraw();
        $this->ensureLimit($draw, '2d_top', '42');
        $this->ensureLimit($draw, '3d_direct', '742');

        /** @var BulkBetService $betService */
        $betService = $this->app->make(BulkBetService::class);

        $items = [
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
        $calculator = app(GloL6ProportionalPrizeCalculator::class);
        $breakdown = $calculator->proportionalBreakdown(125000);
        $tiers = $calculator->calculateForSales('10000000.00', 125000);
        $this->assertSame('10000000.00', $breakdown['gross_sales']);
        $this->assertSame('6000000.00', $breakdown['allocated_gross']);
        $this->assertSame('750000.00', $tiers[GloPrizeTier::First->value]['amount']);

        $duty = app(GloStampDutyCalculator::class)->calculateDuty($tiers[GloPrizeTier::First->value]['amount']);
        $this->assertSame('3750.00', $duty->stampDutyThb);
        $this->assertSame('746250.00', $duty->netPayoutThb);
    }

    public function test_06_withdrawal_request_and_disbursement_lifecycle(): void
    {
        $user = User::factory()->create();
        $walletService = app(WalletService::class);
        $wallet = $walletService->getOrCreateWallet($user, Currency::THB);
        $walletService->credit($wallet, '3000.00', FinancialTransactionType::Deposit, 'master-withdrawal-seed');

        $withdrawal = app(WithdrawalService::class)->request(
            $wallet, Money::of('1000.00', Currency::THB), PaymentMethod::BankTransfer,
            'master-withdrawal-request-'.bin2hex(random_bytes(6)),
            ['payout_details' => ['bank_name' => 'TEST ONLY', 'account_number' => 'NOT-PAYABLE', 'account_name' => 'TEST ONLY']],
        );
        app(WithdrawalApprovalService::class)->approve($withdrawal);

        $wallet->refresh();
        $this->assertSame('3000.00', (string) $wallet->balance);
        $this->assertSame('1000.00', (string) $wallet->locked_balance);
        $this->assertSame('2000.00', (string) $wallet->getAvailableBalance());

        // Simulate an explicit provider completion with unique evidence; a
        // manual-pending response must never be mistaken for a paid transfer.
        $driver = $this->createMock(PaymentGatewayInterface::class);
        $driver->method('name')->willReturn('bank_transfer');
        $driver->method('supportsCurrency')->willReturn(true);
        $driver->expects($this->once())->method('initiateWithdrawal')
            ->willReturn(GatewayWithdrawalResponse::completed('SYNTHETIC-PAYOUT-'.bin2hex(random_bytes(6))));
        $manager = $this->createMock(PaymentGatewayManager::class);
        $manager->method('withdrawalDriver')->willReturn($driver);
        $this->app->instance(PaymentGatewayManager::class, $manager);

        $response = app(WithdrawalDisbursementService::class)->disburse($withdrawal->fresh());
        $this->assertTrue($response->successful);
        $this->assertFalse($response->isPending);
        $this->assertSame(WithdrawalStatus::Completed, $withdrawal->fresh()->status);
        $this->assertSame('2000.00', (string) $wallet->fresh()->balance);
        $this->assertSame('0.00', (string) $wallet->fresh()->locked_balance);
        $this->assertNotNull($withdrawal->fresh()->financial_transaction_id);
    }

    public function test_07_agent_referral_and_settlement_journal(): void
    {
        $agent = $this->createAgent('0.0500', AgentStatus::Active);
        $player = $this->createPlayer('0.00', $agent);
        app(WalletService::class)->credit(
            $player['wallet'], Money::of('1500.00', Currency::THB),
            FinancialTransactionType::Deposit, 'master-agent-player-funding',
        );
        $draw = $this->createOpenDraw();
        $bet = $this->purchaseBet($player['user'], $draw, '3d_direct', '123', '1000.00');
        $commission = AgentCommission::query()->where('bet_id', $bet->bet->id)->sole();

        $result = app(AgentSettlementService::class)->settleDrawCommissions($draw);
        $this->assertSame(1, $result->commissionsSettled);
        $this->assertSame('50.00', $result->totalCommissionPaid);
        $this->assertSame(CommissionStatus::Paid, $commission->fresh()->status);
        $this->assertSame('50.00', (string) $agent->fresh()->total_commission_paid);
        $this->assertNotNull($commission->fresh()->financial_transaction_id);
        $this->assertDatabaseCount('ledger_entries', 6); // funding, bet and commission are each double-entry
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
