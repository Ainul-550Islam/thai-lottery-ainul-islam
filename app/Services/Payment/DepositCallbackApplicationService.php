<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\DTOs\Payment\PaymentCallbackData;
use App\Enums\DepositStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Models\Deposit;
use App\Models\Payment;
use App\Services\Finance\DepositAdmission;
use App\Services\Finance\DepositApprovalService;
use App\Services\Finance\DepositCompletionService;
use App\Services\Finance\DepositIntegrityGuard;
use App\Services\Finance\WalletLockService;
use Illuminate\Support\Facades\DB;

/** Verified-envelope domain caller only. Wallet -> current player -> deposit -> Payment -> reference owner -> ledger lock order. */
final class DepositCallbackApplicationService
{
    public function apply(PaymentCallbackData $callback, Payment $route): array
    {
        $deposit = Deposit::query()->findOrFail($route->payable_id);

        return DB::transaction(function () use ($callback, $route, $deposit): array {
            $wallet = app(WalletLockService::class)->lock((int) $deposit->wallet_id);
            app(DepositAdmission::class)->lockPlayer($wallet);
            $row = Deposit::query()->lockForUpdate()->findOrFail($deposit->id);
            $payment = Payment::query()->lockForUpdate()->findOrFail($route->id);
            $guard = app(DepositIntegrityGuard::class);
            $guard->assertTerms($row, $wallet);
            [$provider, $reference] = $guard->reference($row, ['provider' => $callback->providerCode, 'provider_reference' => $callback->externalReference]);
            if ($payment->payable_type !== Deposit::class || (int) $payment->payable_id !== (int) $row->id
                || (int) $payment->user_id !== (int) $row->user_id || $payment->currency !== $row->currency
                || $callback->currency !== $row->currency->value || bccomp($callback->amount, (string) $row->amount, 2) !== 0
                || bccomp((string) $payment->amount, (string) $row->amount, 2) !== 0 || bccomp((string) $payment->fee, (string) $row->fee, 2) !== 0
                || app(WithdrawalProviderAccountService::class)->canonical((string) $payment->gateway) !== $provider
                || app(WithdrawalProviderAccountService::class)->canonical($payment->method->value) !== $provider
                || $payment->gateway_reference !== $reference) {
                $guard->refuse();
            }
            $scope = app(DepositProviderAccountService::class)->assertActive($row);
            if ($callback->providerStatus === PaymentTransactionStatus::Reversed) {
                $guard->refuse(); // A normalized word is not an authorized original-transaction reversal or refund.
            }
            $replayed = $row->status === DepositStatus::Confirmed;
            if ($replayed) {
                app(DepositCompletionService::class)->verifySettled($row);
            }
            if ($callback->providerStatus === PaymentTransactionStatus::Succeeded) {
                if (! $replayed && ! in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Authorized], true)) {
                    $guard->refuse();
                }
                if (! $replayed) {
                    app(FinancialProviderReferenceService::class)->bind($row, $provider, $scope, $reference, $payment);
                    if ($row->status === DepositStatus::Pending) {
                        app(DepositApprovalService::class)->approve($row, null, 'Approved from verified callback original facts.');
                        $row->refresh();
                    }
                    $payment->status = PaymentStatus::Captured;
                    $payment->captured_at = now();
                    $payment->save();
                }
                app(DepositCompletionService::class)->complete($row, options: ['provider' => $provider, 'provider_reference' => $reference]);
                if ($replayed) {
                    return ['payment' => $payment, 'replayed' => true, 'applied_at' => data_get($payment->metadata, 'provider_normalized.at')];
                }
            } else {
                if ($replayed || ! in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Authorized], true)) {
                    return ['payment' => $payment, 'replayed' => true, 'applied_at' => data_get($payment->metadata, 'provider_normalized.at')];
                }
                app(FinancialProviderReferenceService::class)->bind($row, $provider, $scope, $reference, $payment);
                $payment->status = $callback->providerStatus === PaymentTransactionStatus::Failed ? PaymentStatus::Failed
                    : ($callback->providerStatus === PaymentTransactionStatus::Processing ? PaymentStatus::Authorized : $payment->status);
            }
            $m = $payment->metadata ?? [];
            $m['provider_normalized'] = ['status' => $callback->providerStatus->value, 'reason' => $callback->failureReason?->value,
                'fingerprint' => $callback->payloadFingerprint, 'at' => now()->toIso8601String()];
            $payment->metadata = $m;
            $payment->save();

            return ['payment' => $payment, 'replayed' => false, 'applied_at' => $m['provider_normalized']['at']];
        }, 3);
    }
}
