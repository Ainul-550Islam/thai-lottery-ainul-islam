<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Exceptions\FinancialException;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Finance\Money;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalService;
use App\Services\Payment\ManualDisbursementQueueService;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class ManualDisbursementQueueTest extends Batch01TestCase
{
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

    public function test_manual_completion_uses_the_finance_settlement_once_and_refuses_a_second_proof(): void
    {
        $this->migrateDatabase();
        app(LedgerAccountSeeder::class)->run();
        [$operatorId, $withdrawal] = $this->createManualWithdrawal();
        $queue = app(ManualDisbursementQueueService::class);
        $task = $queue->enqueue($withdrawal);
        $queue->claim((int) $task->id, $operatorId, 'claim-idempotency-key-0101');

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
    private function createManualWithdrawal(): array
    {
        $now = now();
        $userId = DB::table('users')->insertGetId([
            'name' => 'Batch One Operator', 'email' => 'batch-one-operator@example.test',
            'username' => 'batch-one-operator', 'password' => Hash::make('test-password'),
            'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $walletId = DB::table('wallets')->insertGetId([
            'user_id' => $userId, 'type' => 'primary', 'status' => 'active', 'currency' => 'THB',
            'balance' => '500.00', 'locked_balance' => '0.00', 'total_deposited' => '500.00',
            'total_withdrawn' => '0.00', 'total_wagered' => '0.00', 'total_won' => '0.00',
            'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        config(['finance.withdrawal.kyc_gate_threshold' => '5000.00']);
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
        $approved = app(WithdrawalApprovalService::class)->approve($withdrawal, $userId);
        $processing = app(WithdrawalApprovalService::class)->markProcessing($approved['withdrawal']);

        return [$userId, $processing];
    }
}
