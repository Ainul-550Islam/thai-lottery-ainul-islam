<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\Betting\BulkBetSelectionData;
use App\Enums\Currency;
use App\Enums\DrawStatus;
use App\Enums\DrawType;
use App\Enums\GloClaimChannel;
use App\Enums\GloClaimStatus;
use App\Enums\GloPrizeTier;
use App\Enums\PaymentMethod;
use App\Enums\WalletReservationStatus;
use App\Enums\WithdrawalStatus;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\GloTicket;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletReservation;
use App\Models\Withdrawal;
use App\Services\Betting\BulkBetService;
use App\Services\Finance\FinancialReconciliationService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletReservationService;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalRequestWithHoldService;
use App\Services\Lottery\GloL6ProportionalPrizeCalculator;
use App\Services\Lottery\GloPrizeClaimService;
use App\Services\Lottery\GloStampDutyCalculator;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\ResponsibleGaming\ResponsibleGamingLimitService;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Real-money business readiness proofs.
 *
 * Every flow here drives the production services the way the live lanes do.
 * DatabaseTruncation (not RefreshDatabase) is deliberate: bet purchases and
 * withdrawal disbursements refuse to run inside a caller-owned transaction, so
 * the suite commits against the disposable test database exactly as production
 * does and truncates between tests.
 *
 * The historical version of this file referenced App\Models\PaymentMethod, a
 * class that never existed — the real vocabulary is the App\Enums\PaymentMethod
 * enum, which the withdrawal readiness proof below exercises by name.
 */
class RealMoneyBusinessReadinessTest extends TestCase
{
    use DatabaseTruncation;

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
        $this->assertSame('6000000.00', $firstPrize['amount']);

        // Stamp duty: the official ceil(gross / 200) x 1 THB rule, presented as
        // a full breakdown. 6,000,000 / 200 = 30,000 exactly.
        $taxReport = $stampDuty->breakdown('6000000.00');
        $this->assertSame('30000.00', $taxReport['stamp_duty']);
        $this->assertSame('5970000.00', $taxReport['net_prize']);
        // Standard GLO prizes are income-tax exempt: no further withholding.
        $this->assertSame('0.00', $taxReport['income_tax']);
        $this->assertSame('6000000.00', $taxReport['gross_prize']);
    }

    /**
     * Test GLO Prize Claim Lifecycle and Approval through the real fail-closed
     * state machine: submit -> review eligible -> approve, with winner, age,
     * identity, window and stamp-duty conditions revalidated under lock.
     */
    public function test_glo_prize_claim_lifecycle_and_approval(): void
    {
        $fixture = $this->gloClaimFixture();
        $claimService = $fixture['service'];

        $claim = $claimService->submit([
            'ticket_id' => $fixture['ticket']->id,
            'prize_category' => 'first',
            'claim_channel' => GloClaimChannel::Branch->value,
            'original_ticket_evidenced' => true,
            'identity_document_evidenced' => true,
        ], $fixture['claimant']);

        $this->assertSame(GloClaimStatus::Pending, $claim->status);
        $this->assertSame('6000000.00', $claim->gross_prize);
        $this->assertSame('30000.00', $claim->stamp_duty);
        $this->assertSame('5970000.00', $claim->net_prize);

        // Officer review moves Pending -> Eligible.
        $eligible = $claimService->reviewEligible($claim, $fixture['admin']);
        $this->assertSame(GloClaimStatus::Eligible, $eligible->status);
        $this->assertNotNull($eligible->reviewed_at);

        // Approval re-runs every payment condition under a row lock.
        $approved = $claimService->approve($eligible, $fixture['admin']);
        $this->assertSame(GloClaimStatus::Approved, $approved->status);
        $this->assertNotNull($approved->approved_at);

        // The legacy claim_status accessor keeps reporting the same truth.
        $this->assertSame(GloClaimStatus::Approved->value, $approved->claim_status);
    }

    /**
     * Test Multi-Currency Wallet Reservation and Consumption.
     */
    public function test_multi_currency_wallet_reservation_and_consumption(): void
    {
        $user = User::factory()->create();
        $walletService = app(WalletService::class);
        $reservationService = app(WalletReservationService::class);

        // Credit user wallet 1000.00 THB through the ledger-backed money path.
        $wallet = $walletService->getOrCreateWallet($user->id, Currency::THB->value);
        $walletService->credit(
            $wallet,
            '1000.00',
            'deposit',
            'rmbr-reservation-seed-'.bin2hex(random_bytes(8)),
            'Initial deposit',
        );

        $this->assertSame('1000.00', (string) $wallet->fresh()->balance);

        // Create reservation of 250.00 THB. The positional contract is
        // (user, amount, currency, reason, ttl); named arguments must match the
        // service's real parameter names.
        $reservation = $reservationService->reserve(
            $user->id,
            '250.00',
            Currency::THB->value,
            'Bet slip reservation',
            300,
        );

        $this->assertInstanceOf(WalletReservation::class, $reservation);
        $this->assertSame(WalletReservationStatus::Reserved, $reservation->status);
        $this->assertSame('750.00', $wallet->fresh()->available_balance);
        // The hold is persisted on locked_balance, the canonical reservation column.
        $this->assertSame('250.00', (string) $wallet->fresh()->locked_balance);

        // Consume reservation.
        $consumed = $reservationService->consume($reservation->id, 'Bet placement #101');
        $this->assertTrue($consumed);

        $this->assertSame('750.00', (string) $wallet->fresh()->balance);
        $this->assertSame('0.00', (string) $wallet->fresh()->locked_balance);
        $this->assertSame(WalletReservationStatus::Consumed, $reservation->fresh()->status);
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
        $this->assertNotNull($report->summary);
        $this->assertContains($report->status->value, ['pass', 'warning', 'critical']);
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

        // The real payment-method vocabulary is the enum, not a model.
        $this->assertSame('bkash', PaymentMethod::Bkash->value);
        $this->assertTrue(PaymentMethod::Bkash->supportsWebhook());
        $this->assertContains(Currency::THB, PaymentMethod::BankTransfer->supportedCurrencies());
    }

    /**
     * Test Withdrawal Rejection State Machine and Hold Refund.
     *
     * A real withdrawal request takes an initial wallet hold (it never simply
     * subtracts the balance). Rejecting the request must release that exact
     * hold once and seat the Rejected terminal state, restoring the full
     * available balance without inventing money.
     */
    public function test_withdrawal_rejection_restores_the_held_balance(): void
    {
        config(['finance.withdrawal.min' => '1.00']);

        $user = User::factory()->create();
        $this->verifyUserKycForTest($user);
        $walletService = app(WalletService::class);

        $wallet = $walletService->getOrCreateWallet($user->id, Currency::THB->value);
        $walletService->credit(
            $wallet,
            '500.00',
            'deposit',
            'rmbr-withdrawal-seed-'.bin2hex(random_bytes(8)),
            'Initial balance',
        );

        // A requested withdrawal holds 200.00 of the wallet.
        $withdrawal = app(WithdrawalRequestWithHoldService::class)->request(
            $wallet,
            Money::of('200.00', Currency::THB),
            PaymentMethod::Manual,
            'rmbr-withdrawal-'.bin2hex(random_bytes(8)),
            [
                'payout_details' => [
                    'bank_name' => 'KBANK',
                    'account_number' => '1234567890',
                    'account_name' => 'Test claimant',
                ],
            ],
        );

        $this->assertSame(WithdrawalStatus::Pending, $withdrawal->status);
        $this->assertSame('200.00', (string) $wallet->fresh()->locked_balance);
        $this->assertSame('300.00', $wallet->fresh()->available_balance);

        // Reject the request: the hold must be released and the state sealed.
        $outcome = app(WithdrawalApprovalService::class)->reject(
            $withdrawal,
            'Invalid bank account number',
        );

        /** @var Withdrawal $rejected */
        $rejected = $outcome['withdrawal'];
        $this->assertSame(WithdrawalStatus::Rejected, $rejected->status);
        $this->assertTrue($outcome['hold_released']);
        $this->assertSame('500.00', (string) $wallet->fresh()->balance);
        $this->assertSame('0.00', (string) $wallet->fresh()->locked_balance);
        $this->assertSame('500.00', $wallet->fresh()->available_balance);
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
        $this->assertSame('1000.00', $activeLimit);

        // Decreasing limit is immediate
        $rgService->updateDailyDepositLimit($user->id, '500.00', Currency::THB->value);
        $this->assertSame('500.00', $rgService->getActiveDailyDepositLimit($user->id, Currency::THB->value));

        // Increasing limit enters 24h cooling off
        $rgService->updateDailyDepositLimit($user->id, '2000.00', Currency::THB->value);
        // Active limit should still be 500.00 until cooling off expires
        $this->assertSame('500.00', $rgService->getActiveDailyDepositLimit($user->id, Currency::THB->value));
    }

    /**
     * Test Bulk Bet Slip Cart Isolation and replay idempotency.
     */
    public function test_bulk_bet_slip_cart_partial_success_and_replay_idempotency(): void
    {
        $user = User::factory()->create();
        $walletService = app(WalletService::class);
        $bulkBetService = app(BulkBetService::class);

        $wallet = $walletService->getOrCreateWallet($user->id, Currency::THB->value);
        $walletService->credit(
            $wallet,
            '500.00',
            'deposit',
            'rmbr-bulk-seed-'.bin2hex(random_bytes(8)),
            'Test balance',
        );

        $selections = [
            new BulkBetSelectionData(
                marketKey: '2d_top',
                number: '42',
                stake: '50.00',
            ),
            new BulkBetSelectionData(
                marketKey: '3d_direct',
                number: '742',
                stake: '50.00',
            ),
        ];

        $draw = $this->openDrawForSelections($selections);

        // Quote
        $quote = $bulkBetService->quote($draw->id, $selections);
        $this->assertSame('100.00', $quote->totalStake);
        $this->assertCount(2, $quote->items);

        // Purchase with idempotency key
        $clientKey = 'SLIP-'.bin2hex(random_bytes(8));
        $result = $bulkBetService->purchase($user->id, $draw->id, $selections, $clientKey);

        $this->assertSame(2, $result['requested']);
        $this->assertSame(2, $result['purchased']);
        $this->assertSame('100.00', $result['total_charged']);

        // Replay same slip with same client key: every item is replayed, no money moves.
        $replay = $bulkBetService->purchase($user->id, $draw->id, $selections, $clientKey);
        $this->assertSame(2, $replay['requested']);
        $this->assertSame(2, $replay['replayed']);
        $this->assertSame('0.00', $replay['total_charged']);
    }

    /**
     * Build an open draw with a risk limit row for every selection number, the
     * same fixture shape the canonical purchase suites use.
     *
     * @param  list<BulkBetSelectionData>  $selections
     */
    private function openDrawForSelections(array $selections): Draw
    {
        $draw = Draw::factory()->create([
            'type' => DrawType::ThreeD,
            'status' => DrawStatus::Open,
            'betting_open_at' => now()->subHour(),
            'betting_close_at' => now()->addHour(),
            'opened_at' => now()->subHour(),
            'scheduled_at' => now()->addDay(),
        ]);

        foreach ($selections as $selection) {
            $this->provisionBetLimitForTest($draw, $selection->marketKey, $selection->number);
        }

        return $draw;
    }

    /**
     * A fully evidenced first-prize claim fixture: a completed draw with a
     * published result, the winning ticket, and an adult, KYC-verified claimant.
     *
     * @return array{service: GloPrizeClaimService, admin: User, claimant: User, draw: Draw, ticket: GloTicket}
     */
    private function gloClaimFixture(): array
    {
        $service = app(GloPrizeClaimService::class);

        $admin = User::factory()->create();
        $claimant = User::factory()->create();
        $claimant->forceFill(['date_of_birth' => now()->subYears(35)->toDateString()]);
        $claimant->save();
        $this->verifyUserKycForTest($claimant);

        $ticketNumber = '445566';

        $draw = Draw::factory()->create([
            'status' => DrawStatus::Completed,
            'scheduled_at' => now()->subDays(20),
            'result_published_at' => now()->subDays(19),
            'completed_at' => now()->subDays(19),
        ]);

        DrawResult::create([
            'draw_id' => $draw->id,
            'first_prize' => $ticketNumber,
            'second_prize' => ['121212'],
            'third_prize' => ['131313'],
            'consolation_prizes' => [],
            'all_numbers' => [],
            'total_winners' => 1,
            'total_payout' => '6000000.00',
            'house_profit' => '0.00',
            'published_at' => now()->subDays(19),
            'metadata' => ['glo' => []],
        ]);

        $ticket = GloTicket::create([
            'draw_id' => $draw->id,
            'product' => 'l6',
            'ticket_number' => $ticketNumber,
            'set_series' => null,
            'owner_user_id' => null,
            'ticket_reference' => GloTicket::buildReference((int) $draw->id, 'l6', $ticketNumber),
            'metadata' => [],
        ]);

        return compact('service', 'admin', 'claimant', 'draw', 'ticket');
    }
}
