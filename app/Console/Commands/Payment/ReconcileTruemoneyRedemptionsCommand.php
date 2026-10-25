<?php

declare(strict_types=1);

namespace App\Console\Commands\Payment;

use App\Enums\AuditAction;
use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Enums\RiskLevel;
use App\Enums\TruemoneyRedemptionStatus;
use App\Exceptions\FinancialException;
use App\Models\Deposit;
use App\Models\TruemoneyVoucherRedemption;
use App\Services\Audit\AuditLogService;
use App\Services\Finance\DepositApprovalService;
use App\Services\Finance\DepositCompletionService;
use App\Services\Finance\DepositService;
use App\Services\Finance\Money;
use App\Services\Lock\DistributedLockService;
use App\Services\Payment\PaymentLogSanitizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ReconcileTruemoneyRedemptionsCommand extends Command
{
    protected $signature = 'truemoney:reconcile {--dry-run : Inspect eligible rows without changing financial state}';

    protected $description = 'Reconcile confirmed TrueMoney voucher redemptions without calling the provider again.';

    public function handle(DepositService $deposits, DepositApprovalService $approval, DepositCompletionService $completion, AuditLogService $audit, DistributedLockService $locks): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $rows = TruemoneyVoucherRedemption::query()
            ->where('status', TruemoneyRedemptionStatus::RedeemedUnconfirmed)
            ->orderBy('id')
            ->get();
        $eligible = 0;
        $credited = 0;
        $manual = 0;

        foreach ($rows as $row) {
            try {
                $redemptionDate = $row->created_at?->toDateString() ?? now()->toDateString();
                $locks->runWithLock(
                    'payment:truemoney:user:'.$row->user_id.':'.$redemptionDate,
                    function () use ($row, $deposits, $approval, $completion, $audit, $dryRun, &$eligible, &$credited, &$manual): void {
                        if (! $this->withinDailyAttemptLimit($row)) {
                            $manual++;
                            if (! $dryRun) {
                                $this->flagManual($row, $audit, 'daily_attempt_limit_exceeded');
                            }

                            return;
                        }
                        $evidence = $this->provenAmount($row);
                        if ($evidence === null) {
                            $manual++;
                            if (! $dryRun) {
                                $this->flagManual($row, $audit, 'provider_evidence_incomplete');
                            }

                            return;
                        }
                        $eligible++;
                        if ($dryRun) {
                            return;
                        }

                        try {
                            DB::transaction(function () use ($row, $evidence, $deposits, $approval, $completion, &$credited): void {
                                $current = TruemoneyVoucherRedemption::query()->lockForUpdate()->findOrFail($row->getKey());
                                if ($current->status === TruemoneyRedemptionStatus::Credited
                                    || $current->status !== TruemoneyRedemptionStatus::RedeemedUnconfirmed) {
                                    return;
                                }
                                $user = $current->user()->firstOrFail();
                                $wallet = $user->wallets()->where('currency', Currency::THB->value)->firstOrFail();
                                $expectedReference = 'TMN-'.substr((string) $current->voucher_hash, 0, 16);
                                $deposit = Deposit::query()
                                    ->where('user_id', $user->getKey())
                                    ->where('provider_reference', $expectedReference)
                                    ->first();
                                if (! $deposit instanceof Deposit) {
                                    $deposit = $deposits->request(
                                        wallet: $wallet,
                                        amount: Money::of($evidence, Currency::THB),
                                        method: PaymentMethod::BankTransfer,
                                        idempotencyKey: 'truemoney-'.$current->voucher_hash,
                                        options: [
                                            'provider' => PaymentMethod::BankTransfer->value,
                                            'provider_reference' => $expectedReference,
                                            'metadata' => [
                                                'thai_rails' => [
                                                    'provider_kind' => 'truemoney_voucher',
                                                    'voucher_hash' => $current->voucher_hash,
                                                    'voucher_last4' => $current->voucher_last4,
                                                    'reconciled' => true,
                                                ],
                                            ],
                                        ],
                                    );
                                }
                                if ((int) $deposit->user_id !== (int) $current->user_id
                                    || (int) $deposit->wallet_id !== (int) $wallet->getKey()
                                    || $deposit->currency !== Currency::THB
                                    || $deposit->method !== PaymentMethod::BankTransfer
                                    || $deposit->provider !== PaymentMethod::BankTransfer->value
                                    || $deposit->provider_reference !== $expectedReference
                                    || bccomp((string) $deposit->amount, $evidence, 2) !== 0) {
                                    throw FinancialException::withCode('truemoney_reconcile_evidence_mismatch', 'Stored voucher evidence does not match the canonical deposit.');
                                }
                                if ($deposit->status === \App\Enums\DepositStatus::Pending) {
                                    $deposit = $approval->approve($deposit, note: 'Reconciled provider-confirmed TrueMoney voucher evidence.')['deposit'];
                                }
                                if (! in_array($deposit->status, [\App\Enums\DepositStatus::Approved, \App\Enums\DepositStatus::Processing, \App\Enums\DepositStatus::Confirmed], true)) {
                                    throw FinancialException::withCode('truemoney_reconcile_state_conflict', 'The canonical deposit is not in a creditable state.');
                                }
                                $outcome = $completion->complete($deposit, options: [
                                    'provider' => PaymentMethod::BankTransfer->value,
                                    'provider_reference' => $expectedReference,
                                    'description' => 'Reconciled TrueMoney voucher deposit '.$deposit->reference_number,
                                    'metadata' => ['payment_rail' => 'truemoney_voucher', 'voucher_hash' => $current->voucher_hash, 'reconciled' => true],
                                ]);
                                $current->forceFill([
                                    'status' => TruemoneyRedemptionStatus::Credited,
                                    'amount' => $evidence,
                                    'deposit_id' => $outcome['deposit']->getKey(),
                                    'failure_code' => null,
                                ])->save();
                                $audit->log(
                                    userId: null,
                                    action: AuditAction::Reconcile,
                                    riskLevel: RiskLevel::High,
                                    auditable: $current,
                                    description: 'TrueMoney voucher credit reconciled from stored provider evidence.',
                                    metadata: PaymentLogSanitizer::sanitize([
                                        'voucher_last4' => $current->voucher_last4,
                                        'amount' => $evidence,
                                        'deposit_id' => (int) $outcome['deposit']->getKey(),
                                    ]),
                                );
                                $credited++;
                            }, 3);
                        } catch (Throwable) {
                            $manual++;
                            $this->flagManual($row, $audit, 'reconciliation_requires_manual_review');
                        }
                    },
                    ttlSeconds: 900,
                    maxAttempts: 2,
                );
            } catch (Throwable) {
                $manual++;
                if (! $dryRun) {
                    $this->flagManual($row, $audit, 'reconciliation_lock_unavailable');
                }
            }
        }

        $this->line(sprintf(
            '%s eligible=%d credited=%d manual_review=%d',
            $dryRun ? 'DRY-RUN' : 'RESULT',
            $eligible,
            $credited,
            $manual,
        ));

        return self::SUCCESS;
    }

    private function withinDailyAttemptLimit(TruemoneyVoucherRedemption $redemption): bool
    {
        $redemptionDate = $redemption->created_at?->toDateString() ?? now()->toDateString();
        $attemptCount = TruemoneyVoucherRedemption::query()
            ->where('user_id', $redemption->user_id)
            ->whereDate('created_at', $redemptionDate)
            ->count();

        return $attemptCount <= max(1, (int) config('truemoney.per_user_daily_count', 5));
    }

    private function provenAmount(TruemoneyVoucherRedemption $redemption): ?string
    {
        $payload = is_array($redemption->provider_payload) ? $redemption->provider_payload : [];
        $amount = $payload['amount'] ?? null;
        $currency = strtoupper((string) ($payload['currency'] ?? ''));
        $status = strtoupper((string) ($redemption->provider_status ?? $payload['provider_status'] ?? ''));
        $httpStatus = $payload['http_status'] ?? null;
        $riskDecision = strtolower((string) ($payload['risk_decision'] ?? ''));
        $payloadStatus = strtoupper((string) ($payload['provider_status'] ?? ''));
        $voucherHash = (string) $redemption->voucher_hash;
        if ($riskDecision !== 'allow'
            || (! is_string($amount) && ! is_int($amount))
            || $currency !== 'THB'
            || strtoupper((string) $redemption->currency) !== Currency::THB->value
            || preg_match('/\A[a-f0-9]{64}\z/D', $voucherHash) !== 1
            || ! in_array($status, ['SUCCESS', 'SUCCEEDED', '0000', '200'], true)
            || ($payloadStatus !== '' && $payloadStatus !== $status)
            || ! is_int($httpStatus) || $httpStatus < 200 || $httpStatus >= 300
            || preg_match('/\A[0-9]+(?:\.[0-9]{1,2})?\z/D', (string) $amount) !== 1) {
            return null;
        }
        try {
            $money = Money::of((string) $amount, Currency::THB);
            if (! $money->isPositive()
                || ($redemption->amount !== null && bccomp((string) $redemption->amount, $money->toString(), 2) !== 0)
                || bccomp((string) $amount, (string) config('truemoney.min_amount', '0.01'), 2) < 0
                || bccomp((string) $amount, (string) config('truemoney.max_amount', '100000.00'), 2) > 0) {
                return null;
            }
            $redemptionDate = $redemption->created_at?->toDateString() ?? now()->toDateString();
            $todayAmount = TruemoneyVoucherRedemption::query()
                ->where('user_id', $redemption->user_id)
                ->whereDate('created_at', $redemptionDate)
                ->where('id', '!=', $redemption->getKey())
                ->get(['amount'])
                ->reduce(static fn (string $sum, TruemoneyVoucherRedemption $item): string => $item->amount === null ? $sum : bcadd($sum, (string) $item->amount, 2), '0.00');
            if (bccomp(bcadd($todayAmount, $money->toString(), 2), (string) config('truemoney.per_user_daily_amount', '50000.00'), 2) > 0) {
                return null;
            }

            return $money->toString();
        } catch (Throwable) {
            return null;
        }
    }

    private function flagManual(TruemoneyVoucherRedemption $row, AuditLogService $audit, string $reason): void
    {
        $row->forceFill(['failure_code' => $reason])->save();
        $audit->log(
            userId: null,
            action: AuditAction::Reconcile,
            riskLevel: RiskLevel::High,
            auditable: $row,
            description: 'TrueMoney voucher redemption could not be reconciled from stored evidence.',
            metadata: [
                'redemption_id' => (int) $row->getKey(),
                'voucher_last4' => $row->voucher_last4,
                'reason' => $reason,
            ],
        );
    }
}
