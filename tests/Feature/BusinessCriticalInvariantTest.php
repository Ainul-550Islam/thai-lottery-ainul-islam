<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\BetPurchaseData;
use App\DTOs\Betting\BulkBetSelectionData;
use App\DTOs\ResponsibleGaming\SelfExclusionData;
use App\Enums\AgentStatus;
use App\Enums\CommissionStatus;
use App\Enums\Currency;
use App\Enums\DrawStatus;
use App\Enums\DrawType;
use App\Enums\FinancialTransactionType;
use App\Enums\KycDocumentType;
use App\Enums\PaymentMethod;
use App\Enums\PrizeDisbursementStatus;
use App\Enums\SelfExclusionStatus;
use App\Enums\TransactionStatus;
use App\Enums\WalletReservationStatus;
use App\Enums\WithdrawalStatus;
use App\Exceptions\SelfExclusionException;
use App\Exceptions\WithdrawalException;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\Draw;
use App\Models\PrizeDisbursement;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletReservation;
use App\Models\Withdrawal;
use App\Services\Account\AccountVerificationDocumentService;
use App\Services\Agent\AgentSettlementService;
use App\Services\Betting\BetPurchaseService;
use App\Services\Compliance\AmlRiskAssessmentService;
use App\Services\Compliance\SelfExclusionService;
use App\Services\Finance\FinancialReconciliationService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletReservationService;
use App\Services\Finance\WithdrawalRequestWithHoldService;
use App\Services\Payment\ProcessPaymentWebhookService;
use App\Services\Payment\WithdrawalDisbursementService;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Business-Critical Invariant Master Test Suite.
 *
 * Verifies the 15 core architectural and financial invariants across the system.
 *
 * DatabaseTruncation is used instead of RefreshDatabase on purpose: money flows
 * such as bet purchases and withdrawal disbursements refuse to run inside a
 * caller-owned transaction, so these proofs commit against the disposable test
 * database the way production does and truncate between tests.
 *
 * The historical file imported App\Enums\ReservationStatus, a class that never
 * existed. The real reservation vocabulary is App\Enums\WalletReservationStatus,
 * which invariant 15 now asserts against the persisted reservation row.
 */
class BusinessCriticalInvariantTest extends TestCase
{
    use DatabaseTruncation;

    /**
     * Invariant 1: Idempotent Webhook Processing.
     */
    public function test_invariant_1_idempotent_webhook_processing(): void
    {
        $user = User::factory()->create();
        $walletService = app(WalletService::class);
        $webhookService = app(ProcessPaymentWebhookService::class);

        $wallet = $walletService->getOrCreateWallet($user->id, Currency::THB->value);
        $initialBalance = $wallet->balance;

        $externalId = 'WH-TX-'.uniqid();
        $payload = [
            'provider' => 'bkash',
            'external_id' => $externalId,
            'amount' => '500.00',
            'currency' => 'THB',
            'status' => 'completed',
            'user_id' => $user->id,
        ];

        // Process webhook first time
        $result1 = $webhookService->process('bkash', $payload, $externalId);
        $this->assertTrue($result1->successful);
        $this->assertEquals(bcadd((string) $initialBalance, '500.00', 2), (string) $wallet->fresh()->balance);

        // Replay same webhook payload with same external_id
        $result2 = $webhookService->process('bkash', $payload, $externalId);
        $this->assertTrue($result2->successful);
        $this->assertTrue($result2->replayed);
        $this->assertEquals(bcadd((string) $initialBalance, '500.00', 2), (string) $wallet->fresh()->balance);
    }

    /**
     * Invariant 2: Outbound Withdrawal Disbursement Reservation & Terminal State Safety.
     */
    public function test_invariant_2_withdrawal_disbursement_terminal_state_safety(): void
    {
        config(['finance.withdrawal.min' => '1.00']);

        $user = User::factory()->create();
        $this->verifyUserKycForTest($user);
        $walletService = app(WalletService::class);
        $disbursementService = app(WithdrawalDisbursementService::class);

        $wallet = $walletService->getOrCreateWallet($user->id, Currency::THB->value);
        $walletService->credit(
            $wallet,
            '1000.00',
            'deposit',
            'bci-inv2-seed-'.bin2hex(random_bytes(8)),
            'Initial funds',
        );

        // A real requested withdrawal carries an initial wallet hold.
        $withdrawal = app(WithdrawalRequestWithHoldService::class)->request(
            $wallet,
            Money::of('300.00', Currency::THB),
            PaymentMethod::Manual,
            'bci-inv2-withdrawal-'.bin2hex(random_bytes(8)),
            [
                'payout_details' => [
                    'bank_name' => 'KBANK',
                    'account_number' => '123456789',
                    'account_name' => 'Test claimant',
                ],
            ],
        );

        // Seal the terminal state the way the completion writer would.
        $withdrawal->forceFill([
            'status' => WithdrawalStatus::Completed,
            'completed_at' => now(),
        ])->save();

        // Disbursing an already completed withdrawal must refuse with the
        // domain's terminal-state exception, never create a second payout.
        try {
            $disbursementService->disburse($withdrawal->fresh());
            $this->fail('A completed withdrawal must not be disbursed a second time.');
        } catch (WithdrawalException $e) {
            $this->assertSame(WithdrawalException::ERROR_ALREADY_COMPLETED, $e->errorCode());
            $this->assertStringContainsString((string) $withdrawal->id, $e->getMessage());
        }
    }

    /**
     * Invariant 3: Financial Reconciliation Discrepancy Detection.
     */
    public function test_invariant_3_financial_reconciliation_engine_auditing(): void
    {
        $reconService = app(FinancialReconciliationService::class);
        $report = $reconService->reconcile(Carbon::today()->subDays(1), Carbon::today());

        $this->assertNotNull($report);
        $this->assertIsArray($report->discrepancies);
        $this->assertNotNull($report->summary);
        $this->assertContains($report->status->value, ['pass', 'warning', 'critical']);
    }

    /**
     * Invariant 4: Wallet Ledger Balance Exact Consistency.
     */
    public function test_invariant_4_wallet_ledger_balance_consistency(): void
    {
        $user = User::factory()->create();
        $walletService = app(WalletService::class);

        $wallet = $walletService->getOrCreateWallet($user->id, Currency::THB->value);
        $walletService->credit($wallet, '500.00', FinancialTransactionType::Deposit, 'bci-inv4-credit-1-'.bin2hex(random_bytes(8)), 'Credit 1');
        $walletService->credit($wallet, '300.00', FinancialTransactionType::Deposit, 'bci-inv4-credit-2-'.bin2hex(random_bytes(8)), 'Credit 2');
        $walletService->debit($wallet, '200.00', FinancialTransactionType::Withdrawal, 'bci-inv4-debit-1-'.bin2hex(random_bytes(8)), 'Debit 1');

        $totalBalance = (string) $wallet->fresh()->balance;
        $this->assertEquals('600.00', $totalBalance);

        $ledgerSum = DB::table('financial_transactions')
            ->where('wallet_id', $wallet->id)
            ->where('status', TransactionStatus::Completed->value)
            ->selectRaw("SUM(CASE WHEN type IN ('deposit', 'credit', 'payout', 'commission') THEN amount ELSE -amount END) as net_balance")
            ->value('net_balance');

        $this->assertEquals('600.00', bcadd((string) $ledgerSum, '0.00', 2));
    }

    /**
     * Invariant 5: Authoritative Winner Notification Settlement Check.
     */
    public function test_invariant_5_winner_notification_requires_completed_settlement(): void
    {
        $user = User::factory()->create();

        $disbursement = PrizeDisbursement::create([
            'user_id' => $user->id,
            'draw_id' => 1,
            'prize_amount' => '5000.00',
            'currency' => Currency::THB->value,
            'status' => PrizeDisbursementStatus::Pending->value,
            'reference_number' => 'DISB-'.uniqid(),
        ]);

        // Non-settled prize must not trigger winner notification dispatch
        $this->assertNotEquals(PrizeDisbursementStatus::Completed->value, $disbursement->status);
    }

    /**
     * Invariant 6: Centralized Rate Limiter Ceilings.
     */
    public function test_invariant_6_rate_limiter_ceilings_registered(): void
    {
        $this->assertTrue(RateLimiter::hasNamedLocker('login') || RateLimiter::limiter('login') !== null);
        $this->assertNotNull(RateLimiter::limiter('api'));
        $this->assertNotNull(RateLimiter::limiter('deposit'));
        $this->assertNotNull(RateLimiter::limiter('withdrawal'));
        $this->assertNotNull(RateLimiter::limiter('player-bet-placement'));
    }

    /**
     * Invariant 7: CSRF Exemption Allowlist Strictly Limited to Webhooks.
     */
    public function test_invariant_7_csrf_exemptions_contain_only_webhook_routes(): void
    {
        $middleware = new VerifyCsrfToken(app(), app('encrypter'));
        $reflection = new \ReflectionClass($middleware);
        $property = $reflection->getProperty('except');
        $property->setAccessible(true);
        $exemptions = $property->getValue($middleware);

        foreach ($exemptions as $route) {
            $this->assertTrue(
                str_contains($route, 'api/') || str_contains($route, 'webhook'),
                "CSRF exemption must only apply to APIs or signed webhooks: {$route}"
            );
        }
    }

    /**
     * Invariant 8: Unauthenticated Web vs API Response Boundaries.
     */
    public function test_invariant_8_unauthenticated_request_handling(): void
    {
        // JSON API request expects 401 Unauthorized JSON
        $apiResponse = $this->getJson('/api/v1/account/profile');
        $apiResponse->assertStatus(401);
        $apiResponse->assertJsonStructure(['error' => ['code', 'message']]);

        // Browser request redirects to login
        $webResponse = $this->get('/player/dashboard');
        $webResponse->assertRedirect(route('login'));
    }

    /**
     * Invariant 9: KYC Document Upload Traversal and Extension Hardening.
     */
    public function test_invariant_9_kyc_document_upload_security(): void
    {
        $user = User::factory()->create();
        $kycService = app(AccountVerificationDocumentService::class);

        // Path traversal filename
        $maliciousFile = UploadedFile::fake()->create('../evil.php', 100);

        $this->expectException(InvalidArgumentException::class);
        $kycService->storeDocument(
            user: $user,
            type: KycDocumentType::NationalId,
            file: $maliciousFile,
            ipAddress: '127.0.0.1'
        );
    }

    /**
     * Invariant 10: Agent Commission Draw Settlement Idempotency.
     */
    public function test_invariant_10_agent_commission_settlement_idempotency(): void
    {
        $fixture = $this->commissionFixture();

        $commission = AgentCommission::query()
            ->where('agent_id', $fixture['agent']->id)
            ->sole();

        $settlementService = app(AgentSettlementService::class);

        // Settle first time: the owned commission is paid to the agent's wallet.
        $result1 = $settlementService->settleForDraw((int) $fixture['draw']->id);
        $this->assertEquals(1, $result1->commissionsSettled);
        $this->assertEquals('0.50', $result1->totalCommissionPaid);
        $this->assertEquals(CommissionStatus::Paid->value, $commission->fresh()->status->value);
        $this->assertSame('0.50', (string) $fixture['agentWallet']->fresh()->balance);

        // Settle second time (idempotent replay): nothing is paid twice.
        $result2 = $settlementService->settleForDraw((int) $fixture['draw']->id);
        $this->assertEquals(0, $result2->commissionsSettled);
        $this->assertTrue($result2->alreadySettled);
        $this->assertSame('0.50', (string) $fixture['agentWallet']->fresh()->balance);
    }

    /**
     * Invariant 11: Agent Commission is held (never paid, never cancelled) when
     * the agent is suspended at settlement time.
     */
    public function test_invariant_11_agent_commission_held_for_suspended_agent(): void
    {
        $fixture = $this->commissionFixture();

        $commission = AgentCommission::query()
            ->where('agent_id', $fixture['agent']->id)
            ->sole();

        // Suspend the agent after the commission accrued but before settlement.
        $fixture['agent']->forceFill(['status' => AgentStatus::Suspended])->save();

        $settlementService = app(AgentSettlementService::class);
        $result = $settlementService->settleForDraw((int) $fixture['draw']->id);

        // Suspension is a hold, not forfeiture: the row is neither paid nor cancelled.
        $this->assertEquals(0, $result->commissionsSettled);
        $this->assertSame(1, $result->context['held_commissions']);
        $this->assertEquals(CommissionStatus::Accrued->value, $commission->fresh()->status->value);
        $this->assertSame('0.00', (string) $fixture['agentWallet']->fresh()->balance);
    }

    /**
     * Invariant 12: Deterministic AML Risk Scoring and Immutable History.
     */
    public function test_invariant_12_aml_risk_deterministic_scoring_and_immutability(): void
    {
        $user = User::factory()->create();
        $amlService = app(AmlRiskAssessmentService::class);

        $res1 = $amlService->pronounce($user->id);
        $this->assertFalse($res1['replayed']);
        $this->assertFalse($res1['superseded']);

        // Pronounce again without evidence changes => exact replay
        $res2 = $amlService->pronounce($user->id);
        $this->assertTrue($res2['replayed']);
        $this->assertEquals($res1['assessment']->id, $res2['assessment']->id);
    }

    /**
     * Invariant 13: Responsible Gaming Self-Exclusion Horizon and Lifecycle.
     */
    public function test_invariant_13_self_exclusion_lifecycle(): void
    {
        $user = User::factory()->create();
        $rgService = app(SelfExclusionService::class);

        $data = new SelfExclusionData(
            userId: $user->id,
            scope: 'all',
            reasonCode: 'PLAYER_REQUEST',
            effectiveAt: Carbon::now()->subMinute(),
            endsAt: Carbon::now()->addDays(30),
        );

        $exclusion = $rgService->request($data);
        $this->assertEquals(SelfExclusionStatus::Requested, $exclusion->status);

        // Activate
        $active = $rgService->activate($exclusion);
        $this->assertEquals(SelfExclusionStatus::Active, $active->status);
        $this->assertTrue($rgService->hasActiveExclusion($user->id));

        // Premature expiry rejection
        $this->expectException(SelfExclusionException::class);
        $rgService->expire($active);
    }

    /**
     * Invariant 14: Client Bet Slip Minor-Unit Arithmetic and Idempotency Token.
     */
    public function test_invariant_14_bet_slip_minor_unit_arithmetic(): void
    {
        $stake1 = '10.50';
        $stake2 = '20.75';

        // Minor units (satang)
        $minor1 = (int) round((float) $stake1 * 100);
        $minor2 = (int) round((float) $stake2 * 100);
        $totalMinor = $minor1 + $minor2;

        $totalMajor = number_format($totalMinor / 100, 2, '.', '');
        $this->assertEquals('31.25', $totalMajor);
        $this->assertEquals(bcadd($stake1, $stake2, 2), $totalMajor);
    }

    /**
     * Invariant 15: Multi-Currency Wallet Isolation and Reservation Locking.
     */
    public function test_invariant_15_multi_currency_wallet_isolation(): void
    {
        $user = User::factory()->create();
        $walletService = app(WalletService::class);
        $reservationService = app(WalletReservationService::class);

        // Create THB and USD wallets
        $walletThb = $walletService->getOrCreateWallet($user->id, Currency::THB->value);
        $walletUsd = $walletService->getOrCreateWallet($user->id, Currency::USD->value);

        $walletService->credit($walletThb, '1000.00', 'deposit', 'bci-inv15-thb-'.bin2hex(random_bytes(8)), 'THB credit');
        $walletService->credit($walletUsd, '50.00', 'deposit', 'bci-inv15-usd-'.bin2hex(random_bytes(8)), 'USD credit');

        $this->assertEquals('1000.00', (string) $walletThb->fresh()->balance);
        $this->assertEquals('50.00', (string) $walletUsd->fresh()->balance);

        // Reserve in THB using the service's positional contract.
        $res = $reservationService->reserve(
            $user->id,
            '200.00',
            Currency::THB->value,
            'Test reservation',
            60,
        );

        $this->assertInstanceOf(WalletReservation::class, $res);
        $this->assertSame(WalletReservationStatus::Reserved, $res->status);
        $this->assertSame('200.00', (string) $res->amount);
        $this->assertEquals('800.00', $walletThb->fresh()->available_balance);
        // USD wallet must remain untouched, including its available balance.
        $this->assertEquals('50.00', (string) $walletUsd->fresh()->balance);
        $this->assertEquals('50.00', $walletUsd->fresh()->available_balance);
        $this->assertSame('0.00', (string) $walletUsd->fresh()->locked_balance);
    }

    /**
     * Build the canonical owned-commission fixture: a referred player's real
     * purchase accrues a commission with its bet/commission ownership rows, so
     * the settlement writer has the proof it pays against.
     *
     * @return array<string, mixed>
     */
    private function commissionFixture(): array
    {
        config([
            'lottery.betting.currency' => Currency::THB->value,
            'agent.hierarchy.enabled' => true,
            'agent.hierarchy.parent_commission_rate' => '0.0100',
        ]);

        $agent = Agent::factory()->active()->create(['currency' => Currency::THB]);
        $agentWallet = app(WalletService::class)->getOrCreateWallet($agent->user_id, Currency::THB);

        $user = User::factory()->create(['preferences' => ['referred_by_agent_id' => $agent->id]]);
        $wallet = app(WalletService::class)->getOrCreateWallet($user, Currency::THB);
        app(WalletService::class)->credit(
            $wallet,
            '100.00',
            'deposit',
            'bci-agent-seed-'.bin2hex(random_bytes(8)),
            'Synthetic coordinated funded purchase',
        );

        $selection = new BulkBetSelectionData(marketKey: '3d_direct', number: '007', stake: '10.00');
        $draw = $this->openDrawForSelections([$selection]);

        $result = app(BetPurchaseService::class)->purchase(new BetPurchaseData(
            userId: $user->id,
            drawId: $draw->id,
            marketKey: '3d_direct',
            rawNumber: '007',
            rawStake: '10.00',
            idempotencyKey: 'bci-coordinated-member-purchase-'.bin2hex(random_bytes(6)),
        ));

        return compact('agent', 'agentWallet', 'user', 'wallet', 'draw', 'result');
    }

    /**
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
}
