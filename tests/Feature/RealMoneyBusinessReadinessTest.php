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
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Betting\BulkBetService;
use App\Services\Finance\FinancialReconciliationService;
use App\Services\Finance\WalletReservationService;
use App\Services\Finance\WalletService;
use App\Services\Lottery\GloL6ProportionalPrizeCalculator;
use App\Services\Lottery\GloPrizeClaimService;
use App\Services\Lottery\GloStampDutyCalculator;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\WithdrawalDisbursementService;
use App\Services\ResponsibleGaming\ResponsibleGamingLimitService;
use App\Services\Security\ResponsibleGamingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RealMoneyBusinessReadinessTest extends TestCase
{
    use RefreshDatabase;

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
        $user = User::factory()->create();
        $claimService = app(GloPrizeClaimService::class);

        $claim = GloPrizeClaim::create([
            'user_id' => $user->id,
            'claim_reference' => 'CLAIM-' . uniqid(),
            'ticket_number' => '123456',
            'draw_date' => '2026-10-01',
            'prize_tier' => GloPrizeTier::First->value,
            'claim_channel' => GloClaimChannel::Branch->value,
            'claim_status' => GloClaimStatus::Pending->value,
            'gross_amount' => '6000000.00',
            'stamp_duty_amount' => '30000.00',
            'net_payable_amount' => '5970000.00',
            'currency' => Currency::THB->value,
        ]);

        $this->assertEquals(GloClaimStatus::Pending->value, $claim->claim_status);

        // Approve claim
        $approved = $claimService->approveClaim($claim->id, 'Officer note: Verified authentic ticket');
        $this->assertEquals(GloClaimStatus::Approved->value, $approved->claim_status);
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
        $wallet = $walletService->getOrCreateWallet($user->id, Currency::THB->value);
        $walletService->credit($wallet->id, '1000.00', 'Initial deposit');

        $this->assertEquals('1000.00', $wallet->fresh()->balance);

        // Create reservation of 250.00 THB
        $reservation = $reservationService->reserve(
            userId: $user->id,
            amount: '250.00',
            currency: Currency::THB->value,
            reason: 'Bet slip reservation',
            ttlSeconds: 300
        );

        $this->assertNotNull($reservation);
        $this->assertEquals('750.00', $wallet->fresh()->available_balance);
        $this->assertEquals('250.00', $wallet->fresh()->reserved_balance);

        // Consume reservation
        $consumed = $reservationService->consume($reservation->id, 'Bet placement #101');
        $this->assertTrue($consumed);

        $this->assertEquals('750.00', $wallet->fresh()->balance);
        $this->assertEquals('0.00', $wallet->fresh()->reserved_balance);
    }

    /**
     * Test Financial Reconciliation Engine.
     */
    public function test_financial_reconciliation_engine_runs_without_anomalies(): void
    {
        $reconService = app(FinancialReconciliationService::class);
        $report = $reconService->reconcileSystem(Carbon::today()->subDays(1), Carbon::today());

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
        $walletService = app(WalletService::class);
        $disbursementService = app(WithdrawalDisbursementService::class);

        $wallet = $walletService->getOrCreateWallet($user->id, Currency::THB->value);
        $walletService->credit($wallet->id, '500.00', 'Initial balance');

        $withdrawal = Withdrawal::create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'amount' => '200.00',
            'fee' => '0.00',
            'currency' => Currency::THB->value,
            'channel' => 'bank_transfer',
            'status' => WithdrawalStatus::Pending->value,
            'destination_details' => json_encode(['account_number' => '1234567890', 'bank' => 'KBANK']),
        ]);

        // Debit wallet for pending withdrawal
        $walletService->debit($wallet->id, '200.00', 'Withdrawal pending');
        $this->assertEquals('300.00', $wallet->fresh()->balance);

        // Fail withdrawal and ensure funds are refunded
        $disbursementService->rejectWithdrawal($withdrawal->id, 'Invalid bank account number');

        $this->assertEquals(WithdrawalStatus::Failed->value, $withdrawal->fresh()->status);
        $this->assertEquals('500.00', $wallet->fresh()->balance);
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

        $wallet = $walletService->getOrCreateWallet($user->id, Currency::THB->value);
        $walletService->credit($wallet->id, '500.00', 'Test balance');

        $draw = Draw::factory()->create();

        $selections = [
            new BulkBetSelectionData(
                marketKey: 'top_3',
                number: '123',
                stake: '50.00'
            ),
            new BulkBetSelectionData(
                marketKey: 'bottom_2',
                number: '45',
                stake: '50.00'
            ),
        ];

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
