<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\Payment\WithdrawalReconciliationFacts;
use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Exceptions\FinancialException;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Finance\Money;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalService;
use App\Services\Payment\ManualDisbursementQueueService;
use App\Services\Payment\WithdrawalDisbursementService;
use App\Services\Payment\WithdrawalReconciliationService;
use Database\Seeders\LedgerAccountSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class ManualDisbursementQueueTest extends Batch01TestCase
{
    public function test_previous_namespace_resolves_to_the_one_payment_queue_implementation(): void
    {
        $legacy = app(\App\Services\Withdrawal\ManualDisbursementQueueService::class);
        self::assertInstanceOf(ManualDisbursementQueueService::class, $legacy);
        self::assertSame(ManualDisbursementQueueService::class, (new \ReflectionClass($legacy))->getName());
        $files = array_map('basename', glob(app_path('Services/Withdrawal/*.php')));
        sort($files);
        self::assertSame(['WithdrawalKycGateService.php', 'WithdrawalProcessingService.php'], $files);
    }

    public function test_claim_is_idempotent_for_the_owner_and_rejects_a_second_operator(): void
    {
        $this->migrateDatabase();
        [$operatorId, $withdrawal] = $this->createManualWithdrawal();
        $queue = app(ManualDisbursementQueueService::class);
        $task = $queue->enqueue($withdrawal);

        $claimed = $queue->claim((int) $task->id, $operatorId, 'claim-idempotency-key-0001');
        $replayed = $queue->claim((int) $task->id, $operatorId, 'claim-idempotency-key-0001');
        self::assertSame('claimed', $claimed->status);
        self::assertSame($claimed->id, $replayed->id);

        try {
            $queue->claim((int) $task->id, $operatorId + 1, 'claim-idempotency-key-0002');
            self::fail('A claimed manual payout task must not be reassigned by a second claim.');
        } catch (FinancialException $exception) {
            self::assertSame('manual_disbursement_task_already_claimed', $exception->errorCode());
        }
    }

    public function test_unverified_operator_reference_alone_cannot_authorize_manual_completion(): void
    {
        $this->migrateDatabase();
        app(LedgerAccountSeeder::class)->run();
        [$operatorId, $withdrawal] = $this->createManualWithdrawal();
        $queue = app(ManualDisbursementQueueService::class);
        $task = $queue->enqueue($withdrawal);
        $queue->claim((int) $task->id, $operatorId, 'unverified-claim-key-0001');
        try {
            $queue->complete((int) $task->id, $operatorId, 'ARBITRARY-REFERENCE', 'unverified-complete-key-01');
            self::fail('An operator-provided string is not independent evidence of external payout.');
        } catch (FinancialException $exception) {
            self::assertSame('manual_disbursement_evidence_required', $exception->errorCode());
        }
    }

    public function test_http_reference_only_refusal_is_conflict_not_a_server_error(): void
    {
        $this->migrateDatabase();
        app(LedgerAccountSeeder::class)->run();
        $this->seed(RolePermissionSeeder::class);
        [$operatorId, $withdrawal] = $this->createManualWithdrawal();
        $operator = User::query()->findOrFail($operatorId);
        $operator->assignRole('admin');
        $queue = app(ManualDisbursementQueueService::class);
        $task = $queue->enqueue($withdrawal);
        $queue->claim((int) $task->id, $operatorId, 'http-unverified-claim-01');
        $this->actingAs($operator)->withHeader('Idempotency-Key', 'http-unverified-complete-01')
            ->postJson(route('payment_ops.manual-disbursements.complete', ['task' => $task->id]), ['proof_reference' => 'ARBITRARY-REFERENCE'])
            ->assertConflict()->assertJsonPath('error.code', 'manual_disbursement_evidence_required');
        self::assertSame('processing', $withdrawal->fresh()->status->value);
        self::assertSame(0, DB::table('financial_transactions')->where('type', 'withdrawal')->count());
    }

    public function test_unverified_operator_reference_does_not_debit_the_wallet(): void
    {
        $this->migrateDatabase();
        app(LedgerAccountSeeder::class)->run();
        [$operatorId, $withdrawal] = $this->createManualWithdrawal();
        $queue = app(ManualDisbursementQueueService::class);
        $task = $queue->enqueue($withdrawal);
        $queue->claim((int) $task->id, $operatorId, 'unverified-claim-key-0002');
        try {
            $queue->complete((int) $task->id, $operatorId, 'ARBITRARY-REFERENCE', 'unverified-complete-key-02');
        } catch (FinancialException) {
            // Balance assertion independently verifies that refusal kept the hold.
        }
        self::assertSame('500.00', Money::of((string) DB::table('wallets')->where('id', $withdrawal->wallet_id)->value('balance'), Currency::THB)->toString());
        self::assertSame('100.00', Money::of((string) DB::table('wallets')->where('id', $withdrawal->wallet_id)->value('locked_balance'), Currency::THB)->toString());
        self::assertSame('processing', Withdrawal::query()->findOrFail($withdrawal->id)->status->value);
        self::assertSame(0, DB::table('financial_transactions')->where('type', 'withdrawal')->count());
    }

    public function test_independent_reconciliation_settles_once_then_queue_closes_and_refuses_a_second_proof(): void
    {
        $this->migrateDatabase();
        app(LedgerAccountSeeder::class)->run();
        $this->seed(RolePermissionSeeder::class);
        [$operatorId, $withdrawal] = $this->createManualWithdrawal(dispatch: true);
        $maker = User::query()->findOrFail($operatorId);
        $maker->assignRole('admin');
        $checker = User::factory()->create();
        $checker->assignRole('admin');
        $queue = app(ManualDisbursementQueueService::class);
        $task = $queue->enqueue($withdrawal);
        $queue->claim((int) $task->id, $operatorId, 'claim-idempotency-key-0101');

        $facts = WithdrawalReconciliationFacts::fromArray([
            'internal_reference' => $withdrawal->reference_number,
            'provider' => 'bank_transfer',
            'provider_reference' => 'bank-proof-reference-001',
            'currency' => 'THB',
            'gross_amount' => (string) $withdrawal->amount,
            'net_amount' => (string) $withdrawal->net_amount,
            'outcome' => 'completed',
            'observed_at' => now()->addSecond()->toIso8601String(),
        ]);
        $service = app(WithdrawalReconciliationService::class);
        $case = $service->request($maker, (int) $withdrawal->id, $facts,
            UploadedFile::fake()->createWithContent('synthetic-provider-evidence.txt', 'Synthetic evidence for independent human review; not a bank statement.'));
        try {
            $queue->complete((int) $task->id, $operatorId, 'bank-proof-reference-001', 'completion-idempotency-key-01');
            self::fail('A pending case is not an independent review.');
        } catch (FinancialException $exception) {
            self::assertSame('manual_disbursement_evidence_required', $exception->errorCode());
        }
        self::assertSame('500.00', Money::of((string) DB::table('wallets')->where('id', $withdrawal->wallet_id)->value('balance'), Currency::THB)->toString());
        self::assertSame(0, DB::table('financial_transactions')->where('type', 'withdrawal')->count());
        $service->decide($checker, $case, 'apply', 'Independent review of synthetic evidence for this fixture.', true);
        self::assertSame('applied', $case->fresh()->status);
        self::assertNotSame($case->maker_id, $case->fresh()->checker_id);
        self::assertSame('400.00', Money::of((string) DB::table('wallets')->where('id', $withdrawal->wallet_id)->value('balance'), Currency::THB)->toString());
        self::assertSame(1, DB::table('financial_transactions')->where('type', 'withdrawal')->count());
        try {
            $queue->complete((int) $task->id, $operatorId, 'WRONG-REFERENCE', 'completion-idempotency-key-01');
            self::fail('The queue may only close against the applied case reference.');
        } catch (FinancialException $exception) {
            self::assertSame('manual_disbursement_evidence_required', $exception->errorCode());
        }
        self::assertSame(1, DB::table('financial_transactions')->where('type', 'withdrawal')->count());

        $completed = $queue->complete(
            (int) $task->id,
            $operatorId,
            'bank-proof-reference-001',
            'completion-idempotency-key-01',
        );
        self::assertSame('completed', $completed->status);
        self::assertSame('bank-proof-reference-001', $completed->proof_reference);
        self::assertSame('completed', Withdrawal::query()->findOrFail($withdrawal->id)->status->value);
        self::assertSame('400.00', Money::of((string) DB::table('wallets')->where('id', $withdrawal->wallet_id)->value('balance'), Currency::THB)->toString());
        self::assertSame('bank-proof-reference-001', Withdrawal::query()->findOrFail($withdrawal->id)->provider_reference);
        $replayed = $queue->complete((int) $task->id, $operatorId, 'bank-proof-reference-001', 'completion-idempotency-key-01');
        self::assertSame($completed->id, $replayed->id);
        self::assertSame(1, DB::table('financial_transactions')->where('type', 'withdrawal')->count());

        try {
            $queue->complete(
                (int) $task->id,
                $operatorId,
                'different-proof-reference-002',
                'completion-idempotency-key-02',
            );
            self::fail('A completed manual payout task must reject a second proof and settlement.');
        } catch (FinancialException $exception) {
            self::assertSame('manual_disbursement_task_already_completed', $exception->errorCode());
        }
        self::assertSame(1, DB::table('financial_transactions')->where('type', 'withdrawal')->count());
    }

    public function test_overdue_sweep_only_records_notifications_and_never_changes_wallet_money(): void
    {
        $this->migrateDatabase();
        [$operatorId, $withdrawal] = $this->createManualWithdrawal();
        $queue = app(ManualDisbursementQueueService::class);
        $task = $queue->enqueue($withdrawal);
        DB::table('manual_disbursement_tasks')->where('id', $task->id)->update(['sla_due_at' => now()->subHour()]);
        $before = DB::table('wallets')->where('id', $withdrawal->wallet_id)->value('balance');
        $lockedBefore = DB::table('wallets')->where('id', $withdrawal->wallet_id)->value('locked_balance');

        self::assertCount(1, $queue->overdueForSweep(10));
        $queue->recordNotification((int) $task->id);
        self::assertCount(0, $queue->overdueForSweep(10));
        self::assertSame((string) $before, (string) DB::table('wallets')->where('id', $withdrawal->wallet_id)->value('balance'));
        self::assertSame((string) $lockedBefore, (string) DB::table('wallets')->where('id', $withdrawal->wallet_id)->value('locked_balance'));
        self::assertSame('processing', Withdrawal::query()->findOrFail($withdrawal->id)->status->value);
    }

    /** @return array{int, Withdrawal} */
    private function createManualWithdrawal(bool $dispatch = false): array
    {
        $now = now();
        $userId = DB::table('users')->insertGetId([
            'name' => 'Synthetic Beneficiary', 'email' => 'manual-beneficiary@example.test',
            'username' => 'manual-beneficiary', 'password' => Hash::make('test-password'),
            'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $operatorId = DB::table('users')->insertGetId([
            'name' => 'Synthetic Operator', 'email' => 'manual-operator@example.test',
            'username' => 'manual-operator', 'password' => Hash::make('test-password'),
            'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $walletId = DB::table('wallets')->insertGetId([
            'user_id' => $userId, 'type' => 'primary', 'status' => 'active', 'currency' => 'THB',
            'balance' => '500.00', 'locked_balance' => '0.00', 'total_deposited' => '500.00',
            'total_withdrawn' => '0.00', 'total_wagered' => '0.00', 'total_won' => '0.00',
            'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        // This is an isolated synthetic withdrawal; explicitly open the same
        // production gate rather than bypassing it or relying on .env state.
        config(['real_money.enabled' => true, 'finance.withdrawal.kyc_gate_threshold' => '5000.00']);
        $withdrawal = app(WithdrawalService::class)->request(
            wallet: Wallet::query()->findOrFail($walletId),
            amount: Money::of('100.00', Currency::THB),
            method: PaymentMethod::BankTransfer,
            idempotencyKey: 'manual-request-idempotency-01',
            options: [
                'payout_details' => [
                    'destination_type' => 'bank_account', 'bank_code' => '014',
                    'account_number' => '1234567890', 'account_name' => 'Test Recipient',
                ],
                'metadata' => ['manual_disbursement_required' => true],
            ],
        );
        $approved = app(WithdrawalApprovalService::class)->approve($withdrawal, $operatorId);
        if ($dispatch) {
            config(['payment.gateways.bank_transfer.enabled' => true]);
            $response = app(WithdrawalDisbursementService::class)->disburse($approved['withdrawal']);
            self::assertTrue($response->isPending);
            self::assertNull($response->providerReference);
            $processing = $withdrawal->fresh();
        } else {
            $processing = app(WithdrawalApprovalService::class)->markProcessing($approved['withdrawal']);
        }

        return [$operatorId, $processing];
    }
}
