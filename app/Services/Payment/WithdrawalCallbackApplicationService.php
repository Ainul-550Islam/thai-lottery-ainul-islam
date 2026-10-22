<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\DTOs\Payment\PaymentCallbackData;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\WithdrawalStatus;
use App\Models\Payment;
use App\Models\Withdrawal;
use App\Services\Finance\WalletLockService;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalCompletionService;
use App\Services\Finance\WithdrawalIntegrityGuard;
use App\Services\Finance\WithdrawalReservationService;
use Illuminate\Support\Facades\DB;

/** Verified-envelope domain caller only: wallet -> withdrawal -> Payment -> reference owner -> ledger. */
final class WithdrawalCallbackApplicationService
{
    public function apply(PaymentCallbackData $callback, Payment $route): array
    {
        $source = Withdrawal::query()->findOrFail($route->payable_id);

        return DB::transaction(function () use ($callback, $route, $source): array {
            $wallet = app(WalletLockService::class)->lock((int) $source->wallet_id);
            $row = Withdrawal::query()->lockForUpdate()->findOrFail($source->id);
            $payment = Payment::query()->lockForUpdate()->findOrFail($route->id);
            $guard = app(WithdrawalIntegrityGuard::class);
            $guard->assertTerms($row, $wallet);
            $accounts = app(WithdrawalProviderAccountService::class);
            $provider = $accounts->canonical($row->method->value);
            if ($payment->payable_type !== Withdrawal::class || (int) $payment->payable_id !== (int) $row->id
                || (int) $payment->user_id !== (int) $row->user_id || $payment->currency !== $row->currency
                || $callback->currency !== $row->currency->value || bccomp($callback->amount, (string) $row->amount, 2) !== 0
                || bccomp((string) $payment->amount, (string) $row->amount, 2) !== 0 || bccomp((string) $payment->fee, (string) $row->fee, 2) !== 0
                || $accounts->canonical($callback->providerCode) !== $provider || $accounts->canonical((string) $payment->gateway) !== $provider
                || $accounts->canonical($payment->method->value) !== $provider || $payment->gateway_reference !== $callback->externalReference
                || ($row->provider_reference !== null && $row->provider_reference !== $callback->externalReference)) {
                $guard->refuse();
            }
            $accounts->assertActive($row);
            if ($callback->providerStatus === PaymentTransactionStatus::Reversed) {
                $guard->refuse(); // A normalized word cannot refund or reverse the original wallet debit.
            }
            $replayed = $row->status === WithdrawalStatus::Completed;
            if ($replayed) {
                app(WithdrawalCompletionService::class)->verifySettled($row);
                if ($payment->status !== PaymentStatus::Captured) {
                    $guard->refuse();
                }

                return ['payment' => $payment, 'replayed' => true, 'applied_at' => data_get($payment->metadata, 'provider_normalized.at')];
            }
            if ($row->status === WithdrawalStatus::Failed && $payment->status === PaymentStatus::Failed
                && $callback->providerStatus === PaymentTransactionStatus::Failed && $row->financial_transaction_id === null) {
                app(WithdrawalReservationService::class)->assertReleased($row, $wallet);
                app(WithdrawalProviderReferenceService::class)->assertBound($row, $payment);

                return ['payment' => $payment, 'replayed' => true, 'applied_at' => data_get($payment->metadata, 'provider_normalized.at')];
            }
            if (! in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Authorized], true)
                || ! $row->status->canComplete() || $row->financial_transaction_id !== null) {
                $guard->refuse();
            }
            app(WithdrawalProviderReferenceService::class)->bind($row, $provider, $callback->externalReference, $payment);
            $row->provider = $provider;
            $row->provider_reference = $callback->externalReference;
            $row->save();
            if ($callback->providerStatus === PaymentTransactionStatus::Succeeded) {
                $payment->status = PaymentStatus::Captured;
                $payment->captured_at = now();
                $payment->save();
                app(WithdrawalCompletionService::class)->complete($row, options: ['provider' => $provider, 'provider_reference' => $callback->externalReference]);
            } elseif ($callback->providerStatus === PaymentTransactionStatus::Failed) {
                $payment->status = PaymentStatus::Failed;
                $payment->failed_at = now();
                $payment->failure_reason = $callback->failureReason?->value ?? 'unknown';
                $payment->save();
                app(WithdrawalApprovalService::class)->markFailed($row, $payment->failure_reason, verifiedFinalFailure: true);
            } elseif ($callback->providerStatus === PaymentTransactionStatus::Processing) {
                $payment->status = PaymentStatus::Authorized;
                $payment->authorized_at ??= now();
            }
            $metadata = $payment->metadata ?? [];
            $metadata['provider_normalized'] = ['status' => $callback->providerStatus->value, 'reason' => $callback->failureReason?->value,
                'fingerprint' => $callback->payloadFingerprint, 'at' => now()->toIso8601String()];
            $payment->metadata = $metadata;
            $payment->save();

            return ['payment' => $payment, 'replayed' => false, 'applied_at' => $metadata['provider_normalized']['at']];
        }, 3);
    }
}
