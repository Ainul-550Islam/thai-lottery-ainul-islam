<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\Betting\BulkBetSelectionData;
use App\DTOs\ResponsibleGaming\SelfExclusionData;
use App\Enums\CommissionStatus;
use App\Enums\Currency;
use App\Enums\DepositStatus;
use App\Enums\DrawStatus;
use App\Enums\KycDocumentType;
use App\Enums\KycStatus;
use App\Enums\PaymentMethod;
use App\Enums\SelfExclusionStatus;
use App\Enums\WithdrawalStatus;
use App\Models\AgentCommission;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Agent\AgentSettlementService;
use App\Services\Betting\BulkBetService;
use App\Services\Compliance\SelfExclusionService;
use App\Services\Draw\DrawLifecycleService;
use App\Services\Finance\DepositApprovalService;
use App\Services\Finance\DepositCompletionService;
use App\Services\Finance\DepositService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalCompletionService;
use App\Services\Lottery\GloL6ProportionalPrizeCalculator;
use App\Services\Monitoring\HealthCheckService;
use App\Services\Security\KycVerificationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Agent\Concerns\BuildsCommissionCancellation;
use Tests\Feature\Finance\FinancialMasterTestCase;

/**
 * Local integration regression suite across canonical backend services.
 */
final class FinalWholeSystemNoSkipTest extends FinancialMasterTestCase
{
    use BuildsCommissionCancellation;

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
        $reviewer = User::factory()->create();
        /** @var WalletService $walletService */
        $walletService = $this->app->make(WalletService::class);
        $wallet = $walletService->getOrCreateWallet($user, Currency::THB);

        $deposit = app(DepositService::class)->request(
            wallet: $wallet,
            amount: Money::of('5000.00', Currency::THB),
            method: PaymentMethod::Manual,
            idempotencyKey: 'whole-system-deposit-01',
            options: [
                'provider' => 'manual',
                'provider_reference' => 'MANUAL-DEP-001',
            ],
        );

        app(DepositApprovalService::class)->approve($deposit, $reviewer->id, 'Verified manual receipt');

        $completed = app(DepositCompletionService::class)->complete($deposit->fresh(), options: [
            'provider' => 'manual',
            'provider_reference' => 'MANUAL-DEP-001',
        ]);
        $replay = app(DepositCompletionService::class)->complete($deposit->fresh(), options: [
            'provider' => 'manual',
            'provider_reference' => 'MANUAL-DEP-001',
        ]);

        $this->assertTrue($completed['credited']);
        $this->assertTrue($replay['replayed']);
        $this->assertSame(DepositStatus::Confirmed, $deposit->fresh()->status);

        $wallet->refresh();
        $this->assertSame('5000.00', (string) $wallet->balance);
        $this->assertSame('5000.00', (string) $wallet->getAvailableBalance());
    }

    public function test_03_draw_creation_and_lifecycle(): void
    {
        $draw = $this->openBulkDrawForTest([]);

        $this->assertSame(DrawStatus::OPEN, $draw->status);
        $this->assertTrue($draw->isOpen());

        $lifecycle = app(DrawLifecycleService::class);
        $closed = $lifecycle->close($draw);
        $this->assertSame(DrawStatus::CLOSED, $closed->status);
        $this->assertFalse($closed->isOpen());

        $drawing = $lifecycle->markResultPending($closed);
        $this->assertSame(DrawStatus::DRAWING, $drawing->status);
    }

    public function test_04_bulk_bet_placement_with_idempotency_and_satang_math(): void
    {
        $user = User::factory()->create();
        /** @var WalletService $walletService */
        $walletService = $this->app->make(WalletService::class);
        $wallet = $walletService->getOrCreateWallet($user, Currency::THB);
        $this->creditFixture($wallet, '1000.00', 'Initial balance');

        $items = [
            new BulkBetSelectionData(
                marketKey: '2d_top',
                number: '42',
                stake: '50.00',
                potentialPayout: '4500.00'
            ),
            new BulkBetSelectionData(
                marketKey: '3d_direct',
                number: '742',
                stake: '100.00',
                potentialPayout: '90000.00'
            ),
        ];

        $draw = $this->openBulkDrawForTest($items);

        /** @var BulkBetService $betService */
        $betService = $this->app->make(BulkBetService::class);

        $clientKey = 'IDEM-BET-'.bin2hex(random_bytes(6));

        $result = $betService->purchase((int) $user->id, (int) $draw->id, $items, $clientKey);

        $this->assertSame(2, $result['purchased']);
        $this->assertSame('150.00', (string) $result['total_charged']);

        $wallet->refresh();
        $this->assertSame('850.00', (string) $wallet->balance);

        $txCount = FinancialTransaction::count();

        // Idempotency: replay the same request and ensure no duplicate debit
        $replay = $betService->purchase((int) $user->id, (int) $draw->id, $items, $clientKey);

        $this->assertSame(2, $replay['replayed']);
        $this->assertSame('0.00', (string) $replay['total_charged']);
        $this->assertSame($txCount, FinancialTransaction::count());
        $wallet->refresh();
        $this->assertSame('850.00', (string) $wallet->balance);
    }

    public function test_05_proportional_prize_calculation_and_settlement(): void
    {
        /** @var GloL6ProportionalPrizeCalculator $calc */
        $calc = $this->app->make(GloL6ProportionalPrizeCalculator::class);

        $results = $calc->proportionalBreakdown(500000);

        $this->assertIsArray($results);
        $this->assertSame('0.500000000000', $results['fraction']);
        $this->assertSame('24000000.00', $results['allocated_gross']);
        $this->assertNotEmpty($results['tiers']);
    }

    public function test_06_withdrawal_request_and_disbursement_lifecycle(): void
    {
        $user = User::factory()->create();
        $operator = User::factory()->create();
        /** @var WalletService $walletService */
        $walletService = $this->app->make(WalletService::class);
        $wallet = $walletService->getOrCreateWallet($user, Currency::THB);
        $this->creditFixture($wallet, '3000.00', 'Initial balance');

        $withdrawal = $this->pendingWithdrawalForMaster($wallet, '1000.00');
        app(WithdrawalApprovalService::class)->approve($withdrawal, $operator->id);

        $wallet->refresh();
        $this->assertSame('3000.00', (string) $wallet->balance);
        $this->assertSame('1000.00', (string) $wallet->locked_balance);
        $this->assertSame('2000.00', (string) $wallet->getAvailableBalance());

        $completed = app(WithdrawalCompletionService::class)->complete($withdrawal->fresh());
        $replay = app(WithdrawalCompletionService::class)->complete($withdrawal->fresh());

        $this->assertTrue($completed['debited']);
        $this->assertTrue($replay['replayed']);

        $withdrawal->refresh();
        $wallet->refresh();

        $this->assertSame(WithdrawalStatus::Completed, $withdrawal->status);
        $this->assertSame('2000.00', (string) $wallet->balance);
        $this->assertSame('0.00', (string) $wallet->locked_balance);
    }

    public function test_07_agent_referral_and_settlement_journal(): void
    {
        $f = $this->commissionFixture(parent: false);
        $comm = AgentCommission::query()->where('draw_id', $f['draw']->id)->sole();
        $agent = $f['agent'];

        /** @var AgentSettlementService $settleService */
        $settleService = $this->app->make(AgentSettlementService::class);
        $first = $settleService->settleDrawCommissions($f['draw']);
        $replay = $settleService->settleDrawCommissions($f['draw']);

        $comm->refresh();
        $agent->refresh();

        $this->assertSame(1, $first->commissionsSettled);
        $this->assertTrue($replay->alreadySettled);
        $this->assertSame(CommissionStatus::Paid, $comm->status);
        $this->assertSame('0.50', (string) $agent->total_commission_paid);
    }

    public function test_08_responsible_gaming_and_self_exclusion_safeguards(): void
    {
        $user = User::factory()->create();

        /** @var SelfExclusionService $exclusionService */
        $exclusionService = $this->app->make(SelfExclusionService::class);

        $this->assertFalse($exclusionService->hasActiveExclusion($user->id));

        $exclusion = $exclusionService->request(new SelfExclusionData(
            userId: $user->id,
            scope: 'all',
            reasonCode: 'PLAYER_REQUEST',
            effectiveAt: Carbon::now()->subMinute(),
            endsAt: Carbon::now()->addDays(30),
        ));

        $this->assertSame(SelfExclusionStatus::Requested, $exclusion->status);

        $active = $exclusionService->activate($exclusion);
        $this->assertSame(SelfExclusionStatus::Active, $active->status);
        $this->assertTrue($exclusionService->hasActiveExclusion($user->id));
    }

    public function test_09_kyc_document_submission_and_lifecycle(): void
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
