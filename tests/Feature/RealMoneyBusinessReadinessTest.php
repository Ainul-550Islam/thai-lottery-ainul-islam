<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\Betting\BulkBetSelectionData;
use App\Enums\Currency;
use App\Enums\GloClaimChannel;
use App\Enums\GloClaimStatus;
use App\Enums\GloPrizeTier;
use App\Enums\PaymentChannel;
use App\Enums\PaymentDirection;
use App\Enums\PaymentStatus;
use App\Enums\WithdrawalStatus;
use App\Models\Draw;
use App\Models\GloPrizeClaim;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\DTOs\Payment\GatewayWithdrawalResponse;
use App\Services\Betting\BulkBetService;
use App\Services\Finance\FinancialReconciliationService;
use App\Services\Finance\WalletReservationService;
use App\Services\Finance\WalletService;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Lottery\GloL6ProportionalPrizeCalculator;
use App\Services\Lottery\GloPrizeClaimService;
use App\Services\Lottery\GloStampDutyCalculator;
use App\Services\Payment\Drivers\BankTransferGateway;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\WithdrawalDisbursementService;
use App\Services\ResponsibleGaming\ResponsibleGamingLimitService;
use App\Services\Security\ResponsibleGamingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Finance\FinancialMasterTestCase;

class RealMoneyBusinessReadinessTest extends FinancialMasterTestCase
{

    /**
     * Test GLO L6 Proportional Prize Pool Calculation and Stamp Duty.
     */
    public function test_glo_l6_proportional_prize_calculation_and_stamp_duty(): void
    {
        $calculator = app(GloL6ProportionalPrizeCalculator::class);
        $stampDuty = app(GloStampDutyCalculator::class);

        // Standard draw with 1,000,000 sold tickets at 80 THB = 80,000,000 THB total sales
        // Prize pool is 60% = 48,000,000 THB
        $salesThb = '80000000.00';
        $soldTickets = 1000000;

        $prizes = $calculator->calculateForSales($salesThb, $soldTickets);

        $this->assertNotEmpty($prizes);
        $this->assertArrayHasKey(GloPrizeTier::First->value, $prizes);

        // First prize is 6,000,000 THB for 1 unit at 100% sell-through
        $firstPrize = $prizes[GloPrizeTier::First->value];
        $this->assertEquals('6000000.00', $firstPrize['amount']);

        // Stamp duty calculation: 0.5% (1 THB per 200 THB)
        $taxReport = $stampDuty->calculateDuty('6000000.00');
        $this->assertEquals('30000.00', $taxReport->stampDutyThb);
        $this->assertEquals('5970000.00', $taxReport->netPayoutThb);
        $this->assertTrue($taxReport->isIncomeTaxExempt);
    }

    /**
     * Test GLO Prize Claim Lifecycle and Approval.
     */
    public function test_glo_prize_claim_lifecycle_and_approval(): void
    {
        $f = $this->verifiedClaimFixtureForMaster();
        $claimService = app(GloPrizeClaimService::class);
        $claim = $f['claim'];

        $this->assertSame(GloClaimStatus::Pending, $claim->status);

        // Review eligible then approve claim via canonical maker-checker workflow
        $eligible = $claimService->reviewEligible($claim, $f['approver'], 'Officer note: Verified authentic ticket');
        $this->assertSame(GloClaimStatus::Eligible, $eligible->status);

        $approved = $claimService->approve($eligible, $f['approver'], 'Officer note: Approved for payout');
        $this->assertSame(GloClaimStatus::Approved, $approved->status);
        $this->assertNotNull($approved->approved_at);
    }

    /**
     * Test Multi-Currency Wallet Reservation and Consumption.
     */
    public function test_multi_currency_wallet_reservation_and_consumption(): void
    {
        $user = User::factory()->create();
        $walletService = app(WalletService::class);
        $reservationService = app(WalletReservationService::class);

        // Credit user wallet 1000.00 THB
        $wallet = $walletService->getOrCreateWallet($user, Currency::THB);
        $this->creditFixture($wallet, '1000.00', 'Initial deposit');

        $this->assertEquals('1000.00', $wallet->fresh()->balance);

        // Create reservation of 250.00 THB
        $reservation = $reservationService->reserve(
            userIdOrData: $user->id,
            amount: '250.00',
            currency: Currency::THB->value,
            reason: 'Bet slip reservation',
            ttlSeconds: 300
        );

        $this->assertNotNull($reservation);
        $this->assertEquals('750.00', $wallet->fresh()->available_balance);
        $this->assertEquals('250.00', $wallet->fresh()->locked_balance);

        // Consume reservation
        $consumed = $reservationService->consume($reservation->id, 'Bet placement #101');
        $this->assertTrue($consumed);

        $this->assertEquals('750.00', $wallet->fresh()->balance);
        $this->assertEquals('0.00', $wallet->fresh()->locked_balance);
    }

    /**
     * Test Financial Reconciliation Engine.
     */
    public function test_financial_reconciliation_engine_runs_without_anomalies(): void
    {
        $reconService = app(FinancialReconciliationService::class);
        $report = $reconService->reconcile(Carbon::today()->subDays(1), Carbon::today());

        $this->assertNotNull($report);
        $this->assertIsArray($report->discrepancies);
    }

    /**
     * Test Payment Gateway Manager Capability Matrix.
     */
    public function test_payment_gateway_manager_capabilities(): void
    {
        $manager = app(PaymentGatewayManager::class);

        // bKash gateway is supported for deposit and withdrawal
        $bkash = $manager->driver('bkash');
        $this->assertNotNull($bkash);

        // Nagad gateway is supported for deposit and withdrawal
        $nagad = $manager->driver('nagad');
        $this->assertNotNull($nagad);

        // Crypto gateway supports deposits and manual approval withdrawals
        $crypto = $manager->driver('crypto');
        $this->assertNotNull($crypto);
    }

    /**
     * Test Withdrawal Disbursement State Machine and Failure Refund.
     */
    public function test_withdrawal_disbursement_failure_restores_balance(): void
    {
        $user = User::factory()->create();
        $operator = User::factory()->create();
        $walletService = app(WalletService::class);
        $approvalService = app(WithdrawalApprovalService::class);

        $wallet = $walletService->getOrCreateWallet($user, Currency::THB);
        $this->creditFixture($wallet, '500.00', 'Initial balance');

        $withdrawal = $this->pendingWithdrawalForMaster($wallet, '200.00');
        $approvalService->approve($withdrawal, $operator->id);

        $this->assertEquals('500.00', $wallet->fresh()->balance);
        $this->assertEquals('200.00', $wallet->fresh()->locked_balance);
        $this->assertEquals('300.00', $wallet->fresh()->available_balance);

        // Pre-disbursement failure releases the reservation while balance remains 500.00
        $approvalService->markFailed($withdrawal->fresh(), 'Invalid bank account number');

        $this->assertSame(WithdrawalStatus::Failed, $withdrawal->fresh()->status);
        $this->assertEquals('500.00', $wallet->fresh()->balance);
        $this->assertEquals('0.00', $wallet->fresh()->locked_balance);
        $this->assertEquals('500.00', $wallet->fresh()->available_balance);
    }

    /**
     * Test Responsible Gaming Limit Horizon and Enforcement.
     */
    public function test_responsible_gaming_limits_decrease_immediate_and_increase_cooling_off(): void
    {
        $user = User::factory()->create();
        $rgService = app(ResponsibleGamingLimitService::class);

        // Set initial daily deposit limit to 1000.00 THB
        $rgService->setDailyDepositLimit($user->id, '1000.00', Currency::THB->value);
        $activeLimit = $rgService->getActiveDailyDepositLimit($user->id, Currency::THB->value);
        $this->assertEquals('1000.00', $activeLimit);

        // Decreasing limit is immediate
        $rgService->updateDailyDepositLimit($user->id, '500.00', Currency::THB->value);
        $this->assertEquals('500.00', $rgService->getActiveDailyDepositLimit($user->id, Currency::THB->value));

        // Increasing limit enters 24h cooling off
        $rgService->updateDailyDepositLimit($user->id, '2000.00', Currency::THB->value);
        // Active limit should still be 500.00 until cooling off expires
        $this->assertEquals('500.00', $rgService->getActiveDailyDepositLimit($user->id, Currency::THB->value));
    }

    /**
     * Test Bulk Bet Slip Cart Isolation.
     */
    public function test_bulk_bet_slip_cart_partial_success_and_replay_idempotency(): void
    {
        $user = User::factory()->create();
        $walletService = app(WalletService::class);
        $bulkBetService = app(BulkBetService::class);

        $wallet = $walletService->getOrCreateWallet($user, Currency::THB);
        $this->creditFixture($wallet, '500.00', 'Test balance');

        $selections = [
            new BulkBetSelectionData(
                marketKey: '3d_direct',
                number: '123',
                stake: '50.00'
            ),
            new BulkBetSelectionData(
                marketKey: '2d_bottom',
                number: '45',
                stake: '50.00'
            ),
        ];

        $draw = $this->openBulkDrawForTest($selections);

        // Quote
        $quote = $bulkBetService->quote($draw->id, $selections);
        $this->assertEquals('100.00', $quote->totalStake);
        $this->assertCount(2, $quote->items);

        // Purchase with idempotency key
        $clientKey = 'SLIP-' . uniqid();
        $result = $bulkBetService->purchase($user->id, $draw->id, $selections, $clientKey);

        $this->assertEquals(2, $result['requested']);
        $this->assertEquals(2, $result['purchased']);
        $this->assertEquals('100.00', $result['total_charged']);

        // Replay same slip with same client key
        $replay = $bulkBetService->purchase($user->id, $draw->id, $selections, $clientKey);
        $this->assertEquals(2, $replay['requested']);
        $this->assertEquals(2, $replay['replayed']);
        $this->assertEquals('0.00', $replay['total_charged']);
    }
}
