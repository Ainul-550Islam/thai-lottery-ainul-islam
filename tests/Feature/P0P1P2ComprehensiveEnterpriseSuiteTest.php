<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\Betting\BulkBetSelectionData;
use App\DTOs\ResponsibleGaming\SelfExclusionData;
use App\Enums\Currency;
use App\Enums\DrawStatus;
use App\Enums\GloClaimChannel;
use App\Enums\GloClaimStatus;
use App\Enums\GloSourceState;
use App\Enums\KycDocumentType;
use App\Enums\KycStatus;
use App\Enums\LedgerEntryType;
use App\Enums\ReconciliationStatus;
use App\Enums\SelfExclusionStatus;
use App\Enums\WithdrawalStatus;
use App\Exceptions\FinancialException;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\FinancialTransaction;
use App\Models\GloPrizeClaim;
use App\Models\GloTicket;
use App\Models\LedgerEntry;
use App\Models\NationalLotteryDraw;
use App\Models\User;
use App\Services\Betting\BulkBetService;
use App\Services\Compliance\SelfExclusionService;
use App\Services\Draw\DrawLifecycleService;
use App\Services\Draw\DrawResultConfirmationService;
use App\Services\Draw\DrawResultIngestionService;
use App\Services\Finance\FinancialReconciliationService;
use App\Services\Finance\WalletService;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Lottery\GloL6AuthoritativeTicketEngineService;
use App\Services\Lottery\GloPrizeClaimService;
use App\Services\Monitoring\HealthCheckService;
use App\Services\Payment\ProductionPaymentExecutionHubService;
use App\Services\PublicPages\PublishedResultSourceResolver;
use App\Services\Security\KycVerificationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Finance\FinancialMasterTestCase;

/**
 * Enterprise contract suite for L6 pricing, disabled direct writer, payment boundaries,
 * double-entry ledger balance, provenance classification, KYC review, and reconciliation.
 */
final class P0P1P2ComprehensiveEnterpriseSuiteTest extends FinancialMasterTestCase
{
    public function test_p0_01_glo_l6_official_ticket_business_flow(): void
    {
        $player = User::factory()->create([
            'email' => 'glo_player@example.com',
            'date_of_birth' => Carbon::now()->subYears(25)->format('Y-m-d'),
        ]);
        $this->verifyUserKycForTest($player);

        /** @var WalletService $walletService */
        $walletService = $this->app->make(WalletService::class);
        $wallet = $walletService->getOrCreateWallet($player, Currency::THB);
        $this->creditFixture($wallet, '1000.00', 'Initial Deposit');

        $draw = Draw::query()->create([
            'draw_number' => 'GLO-20261001-01',
            'scheduled_at' => Carbon::now()->addDays(3),
            'status' => DrawStatus::OPEN,
            'currency' => Currency::THB,
        ]);

        /** @var GloL6AuthoritativeTicketEngineService $engine */
        $engine = $this->app->make(GloL6AuthoritativeTicketEngineService::class);

        $seat = $engine->seatL6DrawSeries($draw, [
            'units_sold' => 1000000,
            'series_number' => 1,
        ]);

        $this->assertSame(1000000, $seat->units_sold);
        $this->assertSame('80000000.00', (string) $seat->gross_sales);
        $this->assertSame('48000000.00', (string) $seat->proportional_prize_pool);

        $ticket = $engine->purchaseL6Ticket(
            buyer: $player,
            draw: $draw,
            ticketData: ['ticket_number' => '592742', 'set_series' => 1],
            clientKey: 'CLI-KEY-001'
        );
        $replay = $engine->purchaseL6Ticket(
            buyer: $player,
            draw: $draw,
            ticketData: ['ticket_number' => '592742', 'set_series' => 1],
            clientKey: 'CLI-KEY-001'
        );

        $this->assertInstanceOf(GloTicket::class, $ticket);
        $this->assertSame($ticket->id, $replay->id);
        $this->assertSame('592742', $ticket->ticket_number);
        $this->assertSame('80.00', (string) $ticket->price);

        $wallet->refresh();
        $this->assertSame('920.00', (string) $wallet->balance);
    }

    public function test_p0_02_glo_proportional_payout_and_direct_wallet_settlement(): void
    {
        $player = User::factory()->create([
            'date_of_birth' => '1995-05-15',
        ]);
        $this->verifyUserKycForTest($player);
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

        $prizeMath = $engine->computeProportionalPrize($draw, 'first_prize');
        $this->assertSame('3000000.00', $prizeMath['gross_prize']);
        $this->assertSame('15000.00', $prizeMath['stamp_duty']);
        $this->assertSame('2985000.00', $prizeMath['net_prize']);

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
            'identity_reference' => 'kyc:'.$player->id,
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

        try {
            $engine->settleVerifiedClaim($claim, $operator);
            $this->fail('Retired direct wallet settlement writer must refuse execution.');
        } catch (FinancialException $error) {
            $this->assertSame('glo_l6_direct_wallet_settlement_disabled', $error->errorCode());
        }

        $wallet->refresh();
        $this->assertSame('0.00', (string) $wallet->balance);
        $this->assertSame(GloClaimStatus::Approved, $claim->fresh()->status);
    }

    public function test_p0_03_payment_hub_deposit_and_withdrawal_execution(): void
    {
        Config::set('payment.deposit.allowed_methods', ['bkash', 'bank_transfer']);
        Config::set('payment.gateways.bank_transfer.enabled', true);
        Config::set('payment.gateways.bank_transfer.supports_withdrawal', true);

        $user = User::factory()->create();
        $operator = User::factory()->create();
        $wallet = app(WalletService::class)->getOrCreateWallet($user, Currency::THB);

        /** @var ProductionPaymentExecutionHubService $paymentHub */
        $paymentHub = $this->app->make(ProductionPaymentExecutionHubService::class);

        try {
            $paymentHub->initiateDeposit($user, [
                'amount' => '2500.00',
                'channel' => 'bkash',
                'currency' => 'THB',
            ], 'IDEM-DEP-BKASH-001');
            $this->fail('bKash BDT rail must refuse a THB deposit.');
        } catch (FinancialException $error) {
            $this->assertSame('unsupported_gateway_currency', $error->errorCode());
        }

        $this->creditFixture($wallet, '2500.00', 'Enterprise hub fixture');
        $withdrawal = $this->pendingWithdrawalForMaster($wallet, '1000.00');
        app(WithdrawalApprovalService::class)->approve($withdrawal, $operator->id);

        $response = $paymentHub->disburseWithdrawal($withdrawal->fresh(), $operator);
        $this->assertTrue($response->successful);
        $this->assertTrue($response->isPending);

        $withdrawal->refresh();
        $wallet->refresh();
        $this->assertSame(WithdrawalStatus::Processing, $withdrawal->status);
        $this->assertSame('2500.00', (string) $wallet->balance);
        $this->assertSame('1000.00', (string) $wallet->locked_balance);
    }

    public function test_p0_04_wallet_canonical_double_entry_and_single_source(): void
    {
        $user = User::factory()->create();
        /** @var WalletService $walletService */
        $walletService = $this->app->make(WalletService::class);
        $wallet = $walletService->getOrCreateWallet($user, Currency::THB);

        $this->creditFixture($wallet, '500.00', 'Bonus credit');
        $this->debitFixture($wallet, '200.00', 'Entry fee');

        $debits = (string) LedgerEntry::where('entry_type', LedgerEntryType::Debit)->sum('amount');
        $credits = (string) LedgerEntry::where('entry_type', LedgerEntryType::Credit)->sum('amount');

        $wallet->refresh();
        $this->assertSame('300.00', (string) $wallet->balance);
        $this->assertSame('300.00', (string) $wallet->getAvailableBalance());
        $this->assertSame(0, bccomp($debits, $credits, 2));
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
        $this->creditFixture($wallet, '500.00', 'Seed');

        $items = [
            new BulkBetSelectionData(marketKey: '2d_top', number: '19', stake: '100.00', potentialPayout: '9000.00'),
        ];

        $draw = $this->openBulkDrawForTest($items);

        /** @var BulkBetService $bulkService */
        $bulkService = $this->app->make(BulkBetService::class);

        $key = 'UNIQUE-SLIP-TOKEN-001';

        $r1 = $bulkService->purchase((int) $user->id, (int) $draw->id, $items, $key);
        $txCount = FinancialTransaction::count();
        $r2 = $bulkService->purchase((int) $user->id, (int) $draw->id, $items, $key);

        $this->assertSame(1, $r1['purchased']);
        $this->assertSame(1, $r2['replayed']);
        $this->assertSame($txCount, FinancialTransaction::count());

        $wallet->refresh();
        $this->assertSame('400.00', (string) $wallet->balance);
    }

    public function test_p0_07_cross_currency_wallet_isolation(): void
    {
        $user = User::factory()->create();
        /** @var WalletService $walletService */
        $walletService = $this->app->make(WalletService::class);

        $walletTHB = $walletService->getOrCreateWallet($user, Currency::THB);
        $walletUSD = $walletService->getOrCreateWallet($user, Currency::USD);

        $this->creditFixture($walletTHB, '5000.00', 'THB Deposit');
        $this->creditFixture($walletUSD, '100.00', 'USD Deposit');

        $walletTHB->refresh();
        $walletUSD->refresh();

        $this->assertSame('5000.00', (string) $walletTHB->balance);
        $this->assertSame('100.00', (string) $walletUSD->balance);
        $this->assertNotSame($walletTHB->id, $walletUSD->id);
    }

    public function test_p0_08_responsible_gaming_canonical_enforcement(): void
    {
        $user = User::factory()->create();
        /** @var SelfExclusionService $exclusion */
        $exclusion = $this->app->make(SelfExclusionService::class);

        $this->assertFalse($exclusion->hasActiveExclusion($user->id));

        $requested = $exclusion->request(new SelfExclusionData(
            userId: $user->id,
            scope: 'all',
            reasonCode: 'PLAYER_REQUEST',
            effectiveAt: Carbon::now()->subMinute(),
            endsAt: Carbon::now()->addDays(30),
        ));

        $this->assertSame(SelfExclusionStatus::Requested, $requested->status);
        $exclusion->activate($requested);

        $this->assertTrue($exclusion->hasActiveExclusion($user->id));
    }

    public function test_p0_09_result_provenance_validation(): void
    {
        $maker = User::factory()->create();
        $checker = User::factory()->create();
        $draw = $this->openBulkDrawForTest([]);
        $lifecycle = app(DrawLifecycleService::class);
        $draw = $lifecycle->close($draw);
        $draw->scheduled_at = now()->subMinute();
        $draw->save();
        $draw = $lifecycle->markResultPending($draw);

        $payload = [
            'first_prize' => '492817',
            'bottom_two' => '49',
        ];

        app(DrawResultIngestionService::class)->ingest((int) $draw->id, $payload, 'internal', (int) $maker->id);
        app(DrawResultConfirmationService::class)->confirm((int) $draw->id, (int) $checker->id, $payload);

        $result = DrawResult::query()->where('draw_id', $draw->id)->sole();
        $state = app(PublishedResultSourceResolver::class)->resolve($result, $draw->fresh());
        $this->assertSame(GloSourceState::InternalReconciled, $state);
        $this->assertSame('INTERNAL_RECONCILED', $state->value);
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

    public function test_p1_14_kyc_document_storage_and_verification(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['kyc_status' => KycStatus::UNVERIFIED]);
        $reviewer = User::factory()->create();
        $service = app(KycVerificationService::class);

        $upload = UploadedFile::fake()->createWithContent(
            'identity.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j7n8AAAAASUVORK5CYII='),
        );

        $doc = $service->submitDocument($user, KycDocumentType::NationalId, $upload);
        $this->assertSame(KycStatus::PENDING, $doc->status);

        $service->reviewDocument($doc, $reviewer, true);
        $this->assertSame(KycStatus::VERIFIED, $doc->fresh()->status);
        $this->assertTrue($user->fresh()->kycStatus()->isVerified());
    }

    public function test_p1_20_prize_claim_lifecycle_state_machine(): void
    {
        $f = $this->verifiedClaimFixtureForMaster();
        $service = app(GloPrizeClaimService::class);
        $claim = $f['claim'];

        $this->assertSame(GloClaimStatus::Pending, $claim->status);

        $eligible = $service->reviewEligible($claim, $f['approver'], 'Verified ticket and claimant');
        $this->assertSame(GloClaimStatus::Eligible, $eligible->status);

        $approved = $service->approve($eligible, $f['approver'], 'Approved for payout');
        $this->assertSame(GloClaimStatus::Approved, $approved->status);
    }

    public function test_p2_22_agent_and_draw_reconciliation(): void
    {
        $report = app(FinancialReconciliationService::class)->reconcile(
            Carbon::today()->subDay(),
            Carbon::today()->endOfDay(),
            Currency::THB,
            'enterprise-suite',
        );

        $this->assertSame(ReconciliationStatus::Pass, $report->status);
        $this->assertSame([], $report->discrepancies);
    }
}
