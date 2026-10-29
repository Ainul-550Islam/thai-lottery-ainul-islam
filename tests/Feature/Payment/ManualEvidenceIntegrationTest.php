<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\Payment\WithdrawalReconciliationFacts;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Enums\WithdrawalStatus;
use App\Exceptions\FinancialException;
use App\Models\User;
use App\Services\Finance\Money;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalService;
use App\Services\Payment\ManualDisbursementQueueService;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\WithdrawalDisbursementService;
use App\Services\Payment\WithdrawalReconciliationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

/** Real configured SQL engine, local synthetic evidence, no external payout. */
final class ManualEvidenceIntegrationTest extends PaymentTestCase
{
    private function fixture(): array
    {
        Http::preventStrayRequests();
        $this->seed(RolePermissionSeeder::class);
        config(['payment.currency.default' => 'THB', 'payment.country' => 'TH',
            'payment.gateways.bank_transfer.enabled' => true, 'thai_payout.enabled' => false]);
        $player = $this->createPlayer('1000.00', Currency::THB);
        $maker = User::factory()->create();
        $checker = User::factory()->create();
        $maker->assignRole('admin');
        $checker->assignRole('admin');
        $row = app(WithdrawalService::class)->request($player['wallet'], Money::of('400.00', Currency::THB),
            PaymentMethod::BankTransfer, options: ['payout_details' => [
                'destination_type' => 'bank_account', 'bank_code' => '014',
                'account_number' => '1234567890', 'account_name' => 'SYNTHETIC',
            ]]);
        app(WithdrawalApprovalService::class)->approve($row);
        $dispatch = app(WithdrawalDisbursementService::class)->disburse($row->fresh());
        self::assertTrue($dispatch->isPending);
        self::assertNull($dispatch->providerReference);
        $row->refresh();
        $task = DB::table('manual_disbursement_tasks')->where('withdrawal_id', $row->id)->sole();
        app(ManualDisbursementQueueService::class)->claim((int) $task->id, (int) $maker->id, 'manual-evidence-claim-0001');

        return [$player, $maker, $checker, $row, $task];
    }

    private function assertRefused(callable $call): void
    {
        try {
            $call();
            self::fail('A reference alone or an unapplied case is not independent payout review.');
        } catch (FinancialException $exception) {
            self::assertSame('manual_disbursement_evidence_required', $exception->errorCode());
        }
    }

    private function submit(array $fixture): \App\Models\WithdrawalReconciliation
    {
        [, $maker, , $row] = $fixture;
        $facts = WithdrawalReconciliationFacts::fromArray([
            'internal_reference' => $row->reference_number, 'provider' => 'bank_transfer',
            'provider_reference' => 'SYNTHETIC-RECONCILED-REFERENCE', 'currency' => 'THB',
            'gross_amount' => '400.00', 'net_amount' => (string) $row->net_amount,
            'outcome' => 'completed', 'observed_at' => now()->addSecond()->toIso8601String(),
        ]);

        return app(WithdrawalReconciliationService::class)->request($maker, (int) $row->id, $facts,
            UploadedFile::fake()->createWithContent('synthetic.txt', 'Synthetic test evidence, not a genuine authenticated bank statement.'));
    }

    public function test_reference_only_cannot_debit_or_release_the_existing_hold(): void
    {
        [$player, $maker, , $row, $task] = $this->fixture();
        $queue = app(ManualDisbursementQueueService::class);
        $this->assertRefused(fn () => $queue->complete((int) $task->id, (int) $maker->id, 'UNVERIFIED-REFERENCE', 'manual-evidence-complete-01'));
        self::assertSame(WithdrawalStatus::Processing, $row->fresh()->status);
        self::assertSame('1000.00', (string) $player['wallet']->fresh()->balance);
        self::assertSame('400.00', (string) $player['wallet']->fresh()->locked_balance);
        self::assertSame(0, DB::table('financial_transactions')->where('type', 'withdrawal')->count());
        self::assertSame('claimed', DB::table('manual_disbursement_tasks')->find($task->id)->status);
        Http::assertNothingSent();
    }

    public function test_reviewed_case_debits_once_and_queue_close_is_observational(): void
    {
        $fixture = $this->fixture();
        [$player, $maker, $checker, $row, $task] = $fixture;
        $queue = app(ManualDisbursementQueueService::class);
        $case = $this->submit($fixture);
        $this->assertRefused(fn () => $queue->complete((int) $task->id, (int) $maker->id, 'SYNTHETIC-RECONCILED-REFERENCE', 'manual-evidence-complete-02'));
        self::assertSame('1000.00', (string) $player['wallet']->fresh()->balance);
        self::assertSame('400.00', (string) $player['wallet']->fresh()->locked_balance);
        $applied = app(WithdrawalReconciliationService::class)->decide($checker, $case, 'apply',
            'A separate checker independently reviewed this synthetic fixture.', true);
        self::assertSame('applied', $applied->status);
        self::assertNotSame($applied->maker_id, $applied->checker_id);
        self::assertSame(WithdrawalStatus::Completed, $row->fresh()->status);
        self::assertSame('600.00', (string) $player['wallet']->fresh()->balance);
        self::assertSame('0.00', (string) $player['wallet']->fresh()->locked_balance);
        self::assertSame(1, DB::table('financial_transactions')->where('type', 'withdrawal')->count());
        $this->assertRefused(fn () => $queue->complete((int) $task->id, (int) $maker->id, 'OTHER-REFERENCE', 'manual-evidence-complete-02'));
        $closed = $queue->complete((int) $task->id, (int) $maker->id, 'SYNTHETIC-RECONCILED-REFERENCE', 'manual-evidence-complete-02');
        $replay = $queue->complete((int) $task->id, (int) $maker->id, 'SYNTHETIC-RECONCILED-REFERENCE', 'manual-evidence-complete-02');
        self::assertSame('completed', $closed->status);
        self::assertSame($closed->id, $replay->id);
        self::assertSame(1, DB::table('financial_transactions')->where('type', 'withdrawal')->count());
        self::assertSame('600.00', (string) $player['wallet']->fresh()->balance);
        Http::assertNothingSent();
    }

    public static function legacyBdtMethods(): array
    {
        return ['bKash' => [PaymentMethod::Bkash], 'Nagad' => [PaymentMethod::Nagad]];
    }

    #[DataProvider('legacyBdtMethods')]
    public function test_existing_bdt_obligation_is_queued_without_provider_transport(PaymentMethod $method): void
    {
        Http::preventStrayRequests();
        config(['payment.currency.default' => 'BDT', 'payment.country' => 'BD']);
        $player = $this->createPlayer('1000.00', Currency::BDT);
        // The low-level service creates a fixture for an already-approved legacy
        // obligation; customer admission remains separately tested and closed.
        $row = app(WithdrawalService::class)->request($player['wallet'], Money::of('400.00', Currency::BDT),
            $method, options: ['payout_details' => ['wallet_number' => 'NOT-PAYABLE']]);
        app(WithdrawalApprovalService::class)->approve($row);
        $driver = $this->createMock(PaymentGatewayInterface::class);
        $driver->method('name')->willReturn($method->value);
        $driver->method('supportsCurrency')->willReturn(true);
        $driver->expects(self::never())->method('initiateWithdrawal');
        $manager = $this->createMock(PaymentGatewayManager::class);
        $manager->expects(self::once())->method('driver')->with($method->value)->willReturn($driver);
        $manager->expects(self::never())->method('withdrawalDriver');
        $this->app->instance(PaymentGatewayManager::class, $manager);
        $outcome = app(WithdrawalDisbursementService::class)->disburse($row->fresh());
        self::assertTrue($outcome->isPending);
        self::assertNull($outcome->providerReference);
        self::assertSame(WithdrawalStatus::Processing, $row->fresh()->status);
        self::assertSame('1000.00', (string) $player['wallet']->fresh()->balance);
        self::assertSame('400.00', (string) $player['wallet']->fresh()->locked_balance);
        self::assertSame(1, DB::table('manual_disbursement_tasks')->where('withdrawal_id', $row->id)->count());
        self::assertSame(0, DB::table('financial_transactions')->where('type', 'withdrawal')->count());
        Http::assertNothingSent();
    }

    public function test_authorized_operator_read_responses_are_never_cached(): void
    {
        [, $maker] = $this->fixture();
        $this->actingAs($maker);
        $this->getJson(route('payment_ops.manual-disbursements.index'))
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $readiness = $this->getJson(route('payment_ops.readiness'));
        self::assertContains($readiness->status(), [200, 503]);
        $readiness->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_unprivileged_member_cannot_complete_an_operator_task(): void
    {
        [$player, , , $row, $task] = $this->fixture();
        $this->actingAs($player['user'])->withHeader('Idempotency-Key', 'manual-evidence-member-01')
            ->postJson(route('payment_ops.manual-disbursements.complete', ['task' => $task->id]),
                ['proof_reference' => 'UNVERIFIED-REFERENCE'])->assertForbidden();
        self::assertSame(WithdrawalStatus::Processing, $row->fresh()->status);
        self::assertSame('400.00', (string) $player['wallet']->fresh()->locked_balance);
        self::assertSame(0, DB::table('financial_transactions')->where('type', 'withdrawal')->count());
    }
}
