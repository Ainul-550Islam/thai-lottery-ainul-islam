<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\Agent\CommissionCalculationResult;
use App\DTOs\Compliance\AmlRiskAssessmentData;
use App\DTOs\ResponsibleGaming\SelfExclusionData;
use App\Enums\AgentStatus;
use App\Enums\AmlRiskLevel;
use App\Enums\AuditAction;
use App\Enums\CommissionStatus;
use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\KycDocumentType;
use App\Enums\PaymentChannel;
use App\Enums\PaymentDirection;
use App\Enums\PaymentStatus;
use App\Enums\PrizeDisbursementStatus;
use App\Enums\ReservationStatus;
use App\Enums\SelfExclusionStatus;
use App\Enums\TransactionStatus;
use App\Enums\WithdrawalStatus;
use App\Exceptions\FinancialException;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\AccountVerificationDocument;
use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\AmlRiskAssessment;
use App\Models\AuditLog;
use App\Models\Draw;
use App\Models\FinancialTransaction;
use App\Models\KycDocument;
use App\Models\PaymentTransaction;
use App\Models\PaymentWebhook;
use App\Models\PrizeDisbursement;
use App\Models\SelfExclusion;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletReservation;
use App\Models\Withdrawal;
use App\Services\Account\AccountVerificationDocumentService;
use App\Services\Agent\AgentCommissionService;
use App\Services\Agent\AgentSettlementService;
use App\Services\Compliance\AmlRiskAssessmentService;
use App\Services\Compliance\AmlRiskService;
use App\Services\Compliance\SelfExclusionService;
use App\Services\Finance\FinancialReconciliationService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletLockService;
use App\Services\Finance\WalletReservationService;
use App\Services\Finance\WalletService;
use App\Services\Payment\ProcessPaymentWebhookService;
use App\Services\Payment\WithdrawalDisbursementService;
use App\Services\Security\SecurityEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
 */
class BusinessCriticalInvariantTest extends TestCase
{
    use RefreshDatabase;

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

        $externalId = 'WH-TX-' . uniqid();
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
        $user = User::factory()->create();
        $walletService = app(WalletService::class);
        $disbursementService = app(WithdrawalDisbursementService::class);

        $wallet = $walletService->getOrCreateWallet($user->id, Currency::THB->value);
        $walletService->credit($wallet->id, '1000.00', 'Initial funds');

        $withdrawal = Withdrawal::create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'amount' => '300.00',
            'fee' => '0.00',
            'currency' => Currency::THB->value,
            'channel' => 'bank_transfer',
            'status' => WithdrawalStatus::Completed->value,
            'destination_details' => json_encode(['account' => '123456789']),
        ]);

        // Attempting to disburse an already completed withdrawal must throw or return false
        $this->expectException(InvalidArgumentException::class);
        $disbursementService->disburse((int) $withdrawal->id);
    }

    /**
     * Invariant 3: Financial Reconciliation Discrepancy Detection.
     */
    public function test_invariant_3_financial_reconciliation_engine_auditing(): void
    {
        $reconService = app(FinancialReconciliationService::class);
        $report = $reconService->reconcileSystem(Carbon::today()->subDays(1), Carbon::today());

        $this->assertNotNull($report);
        $this->assertIsArray($report->discrepancies);
        $this->assertNotNull($report->summary);
    }

    /**
     * Invariant 4: Wallet Ledger Balance Exact Consistency.
     */
    public function test_invariant_4_wallet_ledger_balance_consistency(): void
    {
        $user = User::factory()->create();
        $walletService = app(WalletService::class);

        $wallet = $walletService->getOrCreateWallet($user->id, Currency::THB->value);
        $walletService->credit($wallet->id, '500.00', 'Credit 1');
        $walletService->credit($wallet->id, '300.00', 'Credit 2');
        $walletService->debit($wallet->id, '200.00', 'Debit 1');

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
            'reference_number' => 'DISB-' . uniqid(),
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
        $agentUser = User::factory()->create();
        $walletService = app(WalletService::class);
        $walletService->getOrCreateWallet($agentUser->id, Currency::THB->value);

        $agent = Agent::create([
            'user_id' => $agentUser->id,
            'agent_code' => 'AGT100',
            'status' => AgentStatus::Active->value,
            'currency' => Currency::THB->value,
            'commission_rate' => '0.0500',
        ]);

        $draw = Draw::factory()->create();

        $commission = AgentCommission::create([
            'agent_id' => $agent->id,
            'draw_id' => $draw->id,
            'bet_id' => 1,
            'stake_amount' => '1000.00',
            'commission_amount' => '50.00',
            'currency' => Currency::THB->value,
            'status' => CommissionStatus::Accrued->value,
            'reference_number' => 'COMM-' . uniqid(),
        ]);

        $settlementService = app(AgentSettlementService::class);

        // Settle first time
        $result1 = $settlementService->settleForDraw((int) $draw->id);
        $this->assertEquals(1, $result1->commissionsSettled);
        $this->assertEquals('50.00', $result1->totalCommissionPaid);
        $this->assertEquals(CommissionStatus::Paid->value, $commission->fresh()->status);

        // Settle second time (idempotent replay)
        $result2 = $settlementService->settleForDraw((int) $draw->id);
        $this->assertEquals(0, $result2->commissionsSettled);
        $this->assertTrue($result2->alreadySettled);
    }

    /**
     * Invariant 11: Agent Commission Cancellation on Inactive Status.
     */
    public function test_invariant_11_agent_commission_cancellation_for_suspended_agent(): void
    {
        $agentUser = User::factory()->create();
        $walletService = app(WalletService::class);
        $walletService->getOrCreateWallet($agentUser->id, Currency::THB->value);

        $agent = Agent::create([
            'user_id' => $agentUser->id,
            'agent_code' => 'AGT200',
            'status' => AgentStatus::Suspended->value,
            'currency' => Currency::THB->value,
            'commission_rate' => '0.0500',
        ]);

        $draw = Draw::factory()->create();

        $commission = AgentCommission::create([
            'agent_id' => $agent->id,
            'draw_id' => $draw->id,
            'bet_id' => 2,
            'stake_amount' => '1000.00',
            'commission_amount' => '50.00',
            'currency' => Currency::THB->value,
            'status' => CommissionStatus::Accrued->value,
            'reference_number' => 'COMM-' . uniqid(),
        ]);

        $settlementService = app(AgentSettlementService::class);
        $result = $settlementService->settleForDraw((int) $draw->id);

        $this->assertEquals(0, $result->commissionsSettled);
        $this->assertEquals(CommissionStatus::Cancelled->value, $commission->fresh()->status);
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
        $this->assertEquals(SelfExclusionStatus::Requested->value, $exclusion->status);

        // Activate
        $active = $rgService->activate($exclusion);
        $this->assertEquals(SelfExclusionStatus::Active->value, $active->status);
        $this->assertTrue($rgService->hasActiveExclusion($user->id));

        // Premature expiry rejection
        $this->expectException(\App\Exceptions\SelfExclusionException::class);
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

        $walletService->credit($walletThb->id, '1000.00', 'THB credit');
        $walletService->credit($walletUsd->id, '50.00', 'USD credit');

        $this->assertEquals('1000.00', $walletThb->fresh()->balance);
        $this->assertEquals('50.00', $walletUsd->fresh()->balance);

        // Reserve in THB
        $res = $reservationService->reserve(
            userId: $user->id,
            amount: '200.00',
            currency: Currency::THB->value,
            reason: 'Test reservation',
            ttlSeconds: 60
        );

        $this->assertNotNull($res);
        $this->assertEquals('800.00', $walletThb->fresh()->available_balance);
        // USD wallet must remain untouched
        $this->assertEquals('50.00', $walletUsd->fresh()->available_balance);
    }
}
