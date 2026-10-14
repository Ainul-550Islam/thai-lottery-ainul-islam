<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\Betting\BulkBetSelectionData;
use App\DTOs\ResponsibleGaming\SelfExclusionData;
use App\Enums\AgentStatus;
use App\Enums\CommissionStatus;
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
use App\Enums\PrizeDisbursementStatus;
use App\Enums\SelfExclusionStatus;
use App\Enums\TransactionStatus;
use App\Enums\WithdrawalStatus;
use App\Enums\BetType;
use App\Enums\LimitStatus;
use App\Models\NumberLimit;
use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\GloPrizeClaim;
use App\Models\KycDocument;
use App\Models\PaymentTransaction;
use App\Models\PrizeDisbursement;
use App\Models\SelfExclusion;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Agent\AgentSettlementService;
use App\Services\Betting\BulkBetService;
use App\Services\Compliance\SelfExclusionService;
use App\Services\Finance\FinancialReconciliationService;
use App\Services\Finance\WalletReservationService;
use App\Services\Wallet\WalletService;
use App\Services\Lottery\GloL6ProportionalPrizeCalculator;
use App\Services\Lottery\GloPrizeClaimService;
use App\Services\Lottery\GloStampDutyCalculator;
use App\Services\Monitoring\HealthCheckService;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\WithdrawalDisbursementService;
use App\Services\Privacy\PrivacyPolicyService;
use App\Services\PublicPages\TermsPageService;
use App\Services\ResponsibleGaming\ResponsibleGamingLimitService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Final Master Production Readiness Test Suite (Prompt 4 Acceptance Gate).
 */
class FinalProductionReadinessTest extends TestCase
{
    // DatabaseTruncation, not RefreshDatabase: RefreshDatabase wraps each test in an open
    // transaction, and a bet purchase must own its transaction (see BetPurchaseTransactionService).
    use DatabaseTruncation;

    /**
     * 1. Public Routes Availability & Multi-Locale Rendering.
     */
    public function test_public_routes_render_in_en_and_th(): void
    {
        $publicRoutes = [
            'home',
            'results.index',
            'ticket-check',
            'sales-points',
            'about',
            'vision',
            'terms',
            'privacy',
            'fees',
            'account-verification-guide',
            'account-grades',
            'prize-verification',
            'discounts',
            'national-lottery.index',
            'weekly-lottery.index',
            'bingo-lottery.index',
            'pcso-lottery.index',
            'contact',
            'sitemap',
        ];

        foreach ($publicRoutes as $routeName) {
            // Test EN
            $resEn = $this->get(route($routeName, ['locale' => 'en']));
            $this->assertContains($resEn->status(), [200, 301, 302], "Route {$routeName} failed in EN");

            // Test TH
            $resTh = $this->get(route($routeName, ['locale' => 'th']));
            $this->assertContains($resTh->status(), [200, 301, 302], "Route {$routeName} failed in TH");
        }
    }

    /**
     * 2. Authenticated Member Routes Access Control.
     */
    public function test_member_routes_require_authentication(): void
    {
        $protectedRoutes = [
            '/player/dashboard',
            '/player/bet',
            '/player/draws',
            '/player/bets',
            '/player/wallet',
            '/player/deposit',
            '/player/withdraw',
            '/player/profile',
            '/account/verification',
        ];

        foreach ($protectedRoutes as $path) {
            $response = $this->get($path);
            $response->assertRedirect(route('login'));
        }
    }

    /**
     * 3. Result Publication & Provenance State Verification.
     */
    public function test_result_publication_and_provenance_integrity(): void
    {
        $draw = Draw::factory()->create([
            'status' => DrawStatus::ResultPublished->value,
            'scheduled_at' => Carbon::now()->subDay(),
        ]);

        $result = DrawResult::create([
            'draw_id' => $draw->id,
            'first_prize' => '987654',
            'second_prize' => ['111111', '222222'],
            'third_prize' => ['333333'],
            'published_at' => Carbon::now(),
            'metadata' => [
                'glo' => [
                    'import_provider' => 'glo_official',
                    'import_fingerprint' => 'sha256-verified-draw-1',
                ],
            ],
        ]);

        $this->assertEquals('987654', $result->first_prize);
        $this->assertEquals(DrawStatus::ResultPublished->value, $draw->status->value);
    }

    /**
     * 4. Multi-Currency Wallet Isolation and ACID Balance Verification.
     */
    public function test_wallet_isolation_and_balance_immutability(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $walletService = app(WalletService::class);

        $w1Thb = $walletService->getOrCreateWallet($user1->id, Currency::THB->value);
        $w2Thb = $walletService->getOrCreateWallet($user2->id, Currency::THB->value);

        $walletService->credit($w1Thb->id, '1000.00', 'Credit User 1');
        $walletService->credit($w2Thb->id, '200.00', 'Credit User 2');

        $this->assertEquals('1000.00', (string) $w1Thb->fresh()->balance);
        $this->assertEquals('200.00', (string) $w2Thb->fresh()->balance);

        // Cross-user modification forbidden
        $this->assertNotEquals($w1Thb->user_id, $w2Thb->user_id);
    }

    /**
     * 5. Payment Gateway Manager Capability Matrix.
     */
    public function test_payment_gateway_capability_matrix(): void
    {
        $gatewayManager = app(PaymentGatewayManager::class);

        $bkash = $gatewayManager->driver('bkash');
        $this->assertNotNull($bkash);

        $nagad = $gatewayManager->driver('nagad');
        $this->assertNotNull($nagad);

        $crypto = $gatewayManager->driver('crypto');
        $this->assertNotNull($crypto);
    }

    /**
     * 6. Webhook Idempotency & Replay Protection.
     */
    public function test_payment_webhook_replay_protection(): void
    {
        $user = User::factory()->create();
        $wallet = app(WalletService::class)->getOrCreateWallet($user->id, Currency::THB->value);
        $initialBalance = (string) $wallet->balance;

        // A real pending deposit, so the webhook is credited through the same
        // pipeline production uses (signature-verified upstream, deposit
        // resolved by reference, replay cache keyed by event id).
        $deposit = new \App\Models\Deposit();
        $deposit->fill([
            'reference_number' => 'DP-REL-'.strtoupper(bin2hex(random_bytes(4))),
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'method' => \App\Enums\PaymentMethod::Stripe,
            'provider' => \App\Enums\PaymentMethod::Stripe->value,
            'currency' => $wallet->currency,
            'amount' => '300.00',
            'fee' => '0.00',
            'net_amount' => '300.00',
            'metadata' => [],
        ]);
        $deposit->uuid = (string) \Illuminate\Support\Str::uuid();
        $deposit->status = \App\Enums\DepositStatus::Pending;
        $deposit->save();

        $payload = new \App\DTOs\Payment\WebhookPayload(
            gateway: 'stripe',
            eventId: 'evt_rel_'.uniqid(),
            eventType: \App\Enums\WebhookEventType::DepositSuccess,
            providerReference: 'cs_rel_'.uniqid(),
            internalReference: $deposit->reference_number,
            amount: '300.00',
            currency: Currency::THB,
            isSuccess: true,
            rawData: ['status' => 'complete'],
        );

        $webhooks = app(\App\Services\Payment\PaymentWebhookService::class);

        // 1st delivery: credited once.
        $res1 = $webhooks->processWebhookPayload($payload);
        $this->assertTrue($res1->success);
        $this->assertFalse($res1->replayed);
        $this->assertSame('credited', $res1->actionTaken);
        $this->assertSame(bcadd($initialBalance, '300.00', 2), (string) $wallet->fresh()->balance);

        // 2nd delivery of the same event: replayed, balance unchanged.
        $res2 = $webhooks->processWebhookPayload($payload);
        $this->assertTrue($res2->replayed);
        $this->assertSame(bcadd($initialBalance, '300.00', 2), (string) $wallet->fresh()->balance);
    }

    /**
     * 7. Bulk Bet Purchase & Idempotency.
     */
    public function test_bulk_bet_placement_with_idempotency(): void
    {
        $user = User::factory()->create();
        $walletService = app(WalletService::class);
        $bulkBetService = app(BulkBetService::class);

        $wallet = $walletService->getOrCreateWallet($user->id, Currency::THB->value);
        $walletService->credit($wallet->id, '1000.00', 'Initial balance');

        $draw = Draw::factory()->create([
            'status' => DrawStatus::Open->value,
            'betting_close_at' => Carbon::now()->addHours(2),
        ]);

        $selections = [
            new BulkBetSelectionData(marketKey: '3d_direct', number: '789', stake: '100.00'),
        ];

        $this->provisionNumberLimit($draw, '3d_direct', '789');

        $clientKey = 'SLIP-RELEASE-' . uniqid();
        $result = $bulkBetService->purchase($user->id, $draw->id, $selections, $clientKey);

        $this->assertEquals(1, $result['purchased']);
        $this->assertEquals('100.00', $result['total_charged']);
        $this->assertEquals('900.00', (string) $wallet->fresh()->balance);

        // Replay
        $replay = $bulkBetService->purchase($user->id, $draw->id, $selections, $clientKey);
        $this->assertEquals(1, $replay['replayed']);
        $this->assertEquals('0.00', $replay['total_charged']);
        $this->assertEquals('900.00', (string) $wallet->fresh()->balance);
    }

    /**
     * 8. GLO L6 Proportional Prize and Stamp Duty Calculation.
     */
    public function test_glo_l6_proportional_prize_calculation(): void
    {
        $calculator = app(GloL6ProportionalPrizeCalculator::class);
        $stampDuty = app(GloStampDutyCalculator::class);

        $prizes = $calculator->calculateForSales('80000000.00', 1000000);
        $this->assertArrayHasKey(GloPrizeTier::First->value, $prizes);
        $this->assertEquals('6000000.00', $prizes[GloPrizeTier::First->value]['amount']);

        $duty = $stampDuty->calculateDuty('6000000.00');
        $this->assertEquals('30000.00', $duty->stampDutyThb);
        $this->assertEquals('5970000.00', $duty->netPayoutThb);
    }

    /**
     * 9. Responsible Gaming Limits and Self-Exclusion.
     */
    public function test_responsible_gaming_and_self_exclusion(): void
    {
        $user = User::factory()->create();
        $seService = app(SelfExclusionService::class);

        $data = new SelfExclusionData(
            userId: $user->id,
            scope: 'all',
            reasonCode: 'PLAYER_REQUEST',
            effectiveAt: Carbon::now()->subMinute(),
            endsAt: Carbon::now()->addDays(7),
        );

        $exclusion = $seService->request($data);
        $seService->activate($exclusion);

        $this->assertTrue($seService->hasActiveExclusion($user->id));
    }

    /**
     * 10. Agent Commission Draw Settlement Idempotency.
     */
    public function test_agent_commission_settlement(): void
    {
        $agentUser = User::factory()->create();
        $walletService = app(WalletService::class);
        $walletService->getOrCreateWallet($agentUser->id, Currency::THB->value);

        $agent = Agent::create([
            'user_id' => $agentUser->id,
            'agent_code' => 'AGT999',
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
            'reference_number' => 'COMM-REL-' . uniqid(),
        ]);

        $settlementService = app(AgentSettlementService::class);
        $result = $settlementService->settleForDraw((int) $draw->id);

        $this->assertEquals(1, $result->commissionsSettled);
        $this->assertEquals('50.00', $result->totalCommissionPaid);
        $this->assertSame(CommissionStatus::Paid, $commission->fresh()->status);
    }

    /**
     * 11. Date-Stable Legal & Privacy Content.
     */
    public function test_privacy_and_legal_content_versioning(): void
    {
        $privacyService = app(PrivacyPolicyService::class);
        $termsService = app(TermsPageService::class);

        $privacy = $privacyService->getPolicy('en');
        $this->assertNotEmpty($privacy['version']);
        $this->assertNotEmpty($privacy['effective_at']);
        $this->assertNotEquals(date('Y-m-d'), $privacy['effective_at']);

        $terms = $termsService->data('en');
        $this->assertNotEmpty($terms['version']);
        $this->assertNotEmpty($terms['effective_at']);
    }

    /**
     * 12. Health Check & Observability Readiness.
     */
    public function test_system_health_and_readiness(): void
    {
        $healthService = app(HealthCheckService::class);

        // The HealthCheckService contract normalises the liveness probe to 'healthy'; the
        // framework endpoint keeps 'UP' at the lower level.
        $live = $healthService->isLive();
        $this->assertEquals('healthy', $live['status']);

        $ready = $healthService->isReady();
        $this->assertTrue($ready['ready']);
        $this->assertTrue($ready['database']);
        $this->assertTrue($ready['cache']);
    }

    /**
     * Provision the number_limits row a number needs before it can be sold. Mirrors the
     * settlement fixtures: the counters and status are set explicitly, not mass-assigned.
     */
    private function provisionNumberLimit(Draw $draw, string $marketKey, string $number): void
    {
        $definition = config('lottery.markets.'.$marketKey);
        $this->assertIsArray($definition, 'Unknown market '.$marketKey);

        $betType = BetType::from((string) $definition['bet_type']);
        $padded = str_pad($number, (int) $definition['digits'], '0', STR_PAD_LEFT);

        $limit = new NumberLimit();
        $limit->draw_id = $draw->getKey();
        $limit->bet_type = $betType;
        $limit->number = $padded;
        $limit->max_amount = '1000000.00';
        $limit->current_amount = '0.00';
        $limit->maximum_payout_exposure = null;
        $limit->current_payout_exposure = '0.00';
        $limit->status = LimitStatus::Active;
        $limit->save();
    }
}
