<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Exceptions\FinancialException;
use App\Models\Withdrawal;
use App\Services\Audit\AuditLogService;
use App\Services\Finance\WalletLockService;
use App\Services\Finance\WithdrawalCompletionService;
use Illuminate\Support\Facades\DB;
use stdClass;

/** Coordinates manual payout evidence and delegates the one financial settlement to WithdrawalCompletionService. */
final class ManualDisbursementQueueService
{
    public function enqueue(Withdrawal $withdrawal): stdClass
    {
        if (! in_array($withdrawal->method->value, ['bkash', 'nagad', 'bank_transfer', 'promptpay'], true)) {
            throw FinancialException::withCode('manual_disbursement_method_unsupported', trans('payment_ops.errors.manual_disbursement_method_unsupported'));
        }

        return DB::transaction(function () use ($withdrawal): stdClass {
            $current = Withdrawal::query()->whereKey($withdrawal->getKey())->lockForUpdate()->first();
            if (! $current instanceof Withdrawal
                || ! in_array($current->status->value, ['approved', 'processing'], true)
                || $current->financial_transaction_id !== null) {
                throw FinancialException::withCode('manual_disbursement_withdrawal_not_completable', trans('payment_ops.errors.manual_disbursement_withdrawal_not_completable'));
            }
            $metadata = is_array($current->metadata) ? $current->metadata : [];
            $manualFlag = ($metadata['manual_disbursement_required'] ?? false) === true
                || ($metadata['requires_manual_disbursement'] ?? false) === true
                || ($metadata['requires_b2c_disbursement'] ?? false) === true
                || ($metadata['manual_bank_wire'] ?? false) === true;
            if (! $manualFlag) {
                throw FinancialException::withCode('manual_disbursement_withdrawal_not_completable', trans('payment_ops.errors.manual_disbursement_withdrawal_not_completable'));
            }
            $withdrawal = $current;
            $existing = DB::table('manual_disbursement_tasks')->where('withdrawal_id', $withdrawal->getKey())->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->method !== $withdrawal->method->value) {
                    throw FinancialException::withCode('manual_disbursement_task_conflict', trans('payment_ops.errors.manual_disbursement_task_conflict'));
                }

                return $existing;
            }
            $slaHours = max(1, (int) config('payment_ops.manual_disbursement.sla_hours', 24));
            $id = DB::table('manual_disbursement_tasks')->insertGetId([
                'withdrawal_id' => $withdrawal->getKey(),
                'method' => $withdrawal->method->value,
                'status' => 'pending',
                'sla_due_at' => now()->addHours($slaHours),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('manual_disbursement_tasks')->where('id', $id)->first();
        });
    }

    public function claim(int $taskId, int $operatorId, string $idempotencyKey): stdClass
    {
        $this->assertIdempotencyKey($idempotencyKey);

        return DB::transaction(function () use ($taskId, $operatorId, $idempotencyKey): stdClass {
            $task = DB::table('manual_disbursement_tasks')->where('id', $taskId)->lockForUpdate()->first();
            if ($task === null) {
                throw FinancialException::withCode('manual_disbursement_task_not_found', trans('payment_ops.errors.manual_disbursement_task_not_found'));
            }
            if ($task->claim_idempotency_key === $idempotencyKey && (int) $task->claimed_by === $operatorId) {
                return $task;
            }
            if ($task->status !== 'pending') {
                throw FinancialException::withCode('manual_disbursement_task_already_claimed', trans('payment_ops.errors.manual_disbursement_task_already_claimed'));
            }
            DB::table('manual_disbursement_tasks')->where('id', $taskId)->update([
                'status' => 'claimed', 'claimed_by' => $operatorId, 'claimed_at' => now(),
                'claim_idempotency_key' => $idempotencyKey, 'updated_at' => now(),
            ]);

            return DB::table('manual_disbursement_tasks')->where('id', $taskId)->first();
        });
    }

    public function complete(int $taskId, int $operatorId, string $proofReference, string $idempotencyKey): stdClass
    {
        $this->assertIdempotencyKey($idempotencyKey);
        $proofReference = trim($proofReference);
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/-]{0,189}\z/D', $proofReference) !== 1) {
            throw FinancialException::withCode('manual_disbursement_proof_invalid', trans('payment_ops.errors.manual_disbursement_proof_invalid'));
        }

        return DB::transaction(function () use ($taskId, $operatorId, $proofReference, $idempotencyKey): stdClass {
            $taskSnapshot = DB::table('manual_disbursement_tasks')->where('id', $taskId)->first();
            if ($taskSnapshot === null) {
                throw FinancialException::withCode('manual_disbursement_task_not_found', trans('payment_ops.errors.manual_disbursement_task_not_found'));
            }
            $withdrawalSnapshot = Withdrawal::query()->whereKey($taskSnapshot->withdrawal_id)->first();
            if (! $withdrawalSnapshot instanceof Withdrawal) {
                throw FinancialException::withCode('manual_disbursement_task_not_found', trans('payment_ops.errors.manual_disbursement_task_not_found'));
            }
            app(WalletLockService::class)->lock((int) $withdrawalSnapshot->wallet_id);
            $withdrawal = Withdrawal::query()->whereKey($withdrawalSnapshot->getKey())->lockForUpdate()->first();
            $task = DB::table('manual_disbursement_tasks')->where('id', $taskId)->lockForUpdate()->first();
            if ($task === null || ! $withdrawal instanceof Withdrawal) {
                throw FinancialException::withCode('manual_disbursement_task_not_found', trans('payment_ops.errors.manual_disbursement_task_not_found'));
            }
            if ((int) $task->withdrawal_id !== (int) $withdrawal->getKey() || $task->method !== $withdrawal->method->value) {
                throw FinancialException::withCode('manual_disbursement_task_conflict', trans('payment_ops.errors.manual_disbursement_task_conflict'));
            }
            if ($task->status === 'completed') {
                if ($task->completion_idempotency_key === $idempotencyKey && $task->proof_reference === $proofReference
                    && (int) $task->claimed_by === $operatorId
                    && $withdrawal->status->value === 'completed' && $withdrawal->financial_transaction_id !== null) {
                    app(WithdrawalCompletionService::class)->verifySettled($withdrawal);

                    return $task;
                }
                throw FinancialException::withCode('manual_disbursement_task_already_completed', trans('payment_ops.errors.manual_disbursement_task_already_completed'));
            }
            if ($task->status !== 'claimed' || (int) $task->claimed_by !== $operatorId) {
                throw FinancialException::withCode('manual_disbursement_task_not_owned', trans('payment_ops.errors.manual_disbursement_task_not_owned'));
            }
            if (! in_array($withdrawal->method->value, ['bkash', 'nagad', 'bank_transfer', 'promptpay'], true)
                || ! in_array($withdrawal->status->value, ['approved', 'processing'], true)
                || $withdrawal->financial_transaction_id !== null) {
                throw FinancialException::withCode('manual_disbursement_withdrawal_not_completable', trans('payment_ops.errors.manual_disbursement_withdrawal_not_completable'));
            }
            $metadata = is_array($withdrawal->metadata) ? $withdrawal->metadata : [];
            $manualFlag = ($metadata['manual_disbursement_required'] ?? false) === true
                || ($metadata['requires_manual_disbursement'] ?? false) === true
                || ($metadata['requires_b2c_disbursement'] ?? false) === true
                || ($metadata['manual_bank_wire'] ?? false) === true;
            if (! $manualFlag) {
                throw FinancialException::withCode('manual_disbursement_withdrawal_not_completable', trans('payment_ops.errors.manual_disbursement_withdrawal_not_completable'));
            }
            $provider = is_string($withdrawal->provider) && trim($withdrawal->provider) !== ''
                ? $withdrawal->provider
                : $withdrawal->method->value;
            app(WithdrawalCompletionService::class)->complete($withdrawal, options: [
                'provider' => $provider,
                'provider_reference' => $proofReference,
                'description' => 'Operator-confirmed manual withdrawal disbursement.',
                'metadata' => [
                    'manual_disbursement_task_id' => $taskId,
                    'manual_disbursement_proof_reference' => $proofReference,
                    'manual_disbursement_operator_id' => $operatorId,
                    'manual_disbursement_idempotency_key' => $idempotencyKey,
                ],
            ]);
            $withdrawal->refresh();
            $metadata = is_array($withdrawal->metadata) ? $withdrawal->metadata : [];
            $metadata['manual_disbursement_proof_reference'] = $proofReference;
            $metadata['manual_disbursement_task_id'] = $taskId;
            $metadata['manual_disbursement_operator_id'] = $operatorId;
            $withdrawal->metadata = $metadata;
            $withdrawal->save();
            app(AuditLogService::class)->log(
                userId: $operatorId,
                action: AuditAction::Withdraw,
                riskLevel: RiskLevel::High,
                auditable: $withdrawal,
                description: 'Operator confirmed external manual payout and completed the held withdrawal using the existing finance settlement service.',
                metadata: [
                    'manual_disbursement_task_id' => $taskId,
                    'proof_reference' => $proofReference,
                    'idempotency_key' => $idempotencyKey,
                    'withdrawal_id' => (int) $withdrawal->getKey(),
                ],
            );
            DB::table('manual_disbursement_tasks')->where('id', $taskId)->update([
                'status' => 'completed', 'proof_reference' => $proofReference,
                'completion_idempotency_key' => $idempotencyKey, 'completed_at' => now(), 'updated_at' => now(),
            ]);

            return DB::table('manual_disbursement_tasks')->where('id', $taskId)->first();
        });
    }

    /** @return list<stdClass> */
    public function overdueForSweep(int $batchSize): array
    {
        return DB::table('manual_disbursement_tasks')->whereIn('status', ['pending', 'claimed'])
            ->where('sla_due_at', '<=', now())
            ->where(function ($query): void {
                $query->whereNull('last_notified_at')->orWhere('last_notified_at', '<=', now()->subHours(max(1, (int) config('payment_ops.manual_disbursement.stale_task_hours', 12))));
            })->orderBy('sla_due_at')->limit(max(1, $batchSize))->get()->all();
    }

    public function recordNotification(int $taskId): void
    {
        DB::table('manual_disbursement_tasks')->where('id', $taskId)->whereIn('status', ['pending', 'claimed'])
            ->update(['last_notified_at' => now(), 'notification_count' => DB::raw('notification_count + 1'), 'updated_at' => now()]);
    }

    private function assertIdempotencyKey(string $key): void
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{15,127}\z/D', $key) !== 1) {
            throw FinancialException::withCode('manual_disbursement_idempotency_key_invalid', trans('payment_ops.errors.manual_disbursement_idempotency_key_invalid'));
        }
    }
}
