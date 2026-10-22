<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\DTOs\Payment\WebhookMoneyFacts;
use App\DTOs\Payment\WebhookPayload;
use App\Enums\DepositStatus;
use App\Enums\PaymentMethod;
use App\Enums\WithdrawalStatus;
use App\Exceptions\FinancialException;
use App\Models\Deposit;
use App\Models\Payment;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Finance\DepositAdmission;
use App\Services\Finance\DepositIntegrityGuard;
use App\Services\Finance\WithdrawalIntegrityGuard;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Binds authenticated, normalized facts to one locked obligation; never moves money. */
final class PaymentWebhookBindingService
{
    public function hasFinancialProof(WebhookPayload $payload): bool
    {
        if ($payload->eventId === '' || strlen($payload->eventId) > 255
            || PaymentMethod::tryFrom($payload->gateway) === null || $payload->currency === null
            || $payload->providerReference === null || trim($payload->providerReference) === ''
            || strlen($payload->providerReference) > 255 || $payload->amount === null
            || WebhookMoneyFacts::decimal($payload->amount) === null
            || bccomp($payload->amount, '0.00', 2) <= 0) {
            return false;
        }

        return $payload->eventType->isSuccess() === $payload->isSuccess;
    }

    /**
     * No fallback from a supplied-but-wrong internal reference to another row.
     * Provider-reference-only lookup is scoped to the provider and must be unique.
     * Lock order is wallet -> obligation -> existing payment; re-read after waiting.
     *
     * @param  class-string<Deposit>|class-string<Withdrawal>  $model
     */
    public function resolve(string $model, WebhookPayload $payload): Deposit|Withdrawal|null
    {
        if (! in_array($model, [Deposit::class, Withdrawal::class], true) || DB::transactionLevel() < 1) {
            throw new LogicException('Webhook binding requires an allow-listed obligation and an owned SQL transaction.');
        }
        if (! $this->hasFinancialProof($payload)) {
            return null;
        }
        $gateway = $this->canonicalGateway($payload->gateway);
        $internal = trim((string) $payload->internalReference);
        $query = $model::query();
        if ($internal !== '') {
            $query->where(fn ($q) => $q->where('reference_number', $internal)->orWhere('uuid', $internal));
        } else {
            $methods = $gateway === 'bank_transfer' ? ['bank_transfer', 'manual'] : [$gateway];
            $query->where('provider_reference', $payload->providerReference)->whereIn('method', $methods);
        }
        $candidates = $query->limit(2)->get();
        if ($candidates->count() !== 1) {
            return null;
        }
        $candidate = $candidates->sole();
        $wallet = Wallet::query()->whereKey($candidate->wallet_id)->lockForUpdate()->first();
        if ($model === Deposit::class && $wallet instanceof Wallet) {
            app(DepositAdmission::class)->lockPlayer($wallet);
        }
        $row = $model::query()->whereKey($candidate->getKey())->lockForUpdate()->first();
        if (! $wallet instanceof Wallet || $row === null
            || (int) $row->wallet_id !== (int) $wallet->id || (int) $row->user_id !== (int) $wallet->user_id
            || $row->currency !== $wallet->currency || $payload->currency !== $row->currency
            || $this->canonicalGateway($row->method->value) !== $gateway
            || ($row->provider && $this->canonicalGateway($row->provider) !== $gateway)
            || ($internal !== '' && ! in_array($internal, [$row->reference_number, $row->uuid], true))) {
            return null;
        }
        $expected = [(string) $row->amount];
        if ($row instanceof Withdrawal) {
            $expected[] = (string) $row->net_amount;
        }
        if (! array_any($expected, fn (string $amount): bool => bccomp($amount, $payload->amount, 2) === 0)) {
            return null;
        }
        if ($row->provider_reference && $row->provider_reference !== $payload->providerReference) {
            return null;
        }
        $payments = Payment::withTrashed()->where('payable_type', $model)->where('payable_id', $row->id)->lockForUpdate()->limit(2)->get();
        if ($payments->count() > 1) {
            return null;
        }
        if ($payment = $payments->first()) {
            if ($payment->trashed() || (int) $payment->user_id !== (int) $row->user_id || $payment->currency !== $row->currency
                || $this->canonicalGateway($payment->method->value) !== $gateway
                || $this->canonicalGateway($payment->gateway) !== $gateway
                || bccomp((string) $payment->amount, (string) $row->amount, 2) !== 0
                || bccomp((string) $payment->fee, (string) $row->fee, 2) !== 0
                || ($payment->gateway_reference && $payment->gateway_reference !== $payload->providerReference)) {
                return null;
            }
        }

        if ($row instanceof Deposit) {
            try {
                app(DepositIntegrityGuard::class)->assertTerms($row, $wallet);
                $scope = app(DepositProviderAccountService::class)->assertActive($row);
                if ($row->status === DepositStatus::Confirmed) {
                    app(FinancialProviderReferenceService::class)->assertBound($row, $gateway, $scope, $payload->providerReference, $payment);
                } else {
                    app(FinancialProviderReferenceService::class)->bind($row, $gateway, $scope, $payload->providerReference, $payment);
                }
            } catch (FinancialException) {
                return null;
            }
        }
        if ($row instanceof Withdrawal) {
            try {
                app(WithdrawalIntegrityGuard::class)->assertTerms($row, $wallet);
                app(WithdrawalProviderAccountService::class)->assertActive($row);
                if ($row->status === WithdrawalStatus::Completed) {
                    app(WithdrawalProviderReferenceService::class)->assertBound($row, $payment);
                }
                if (in_array($row->status, [WithdrawalStatus::Approved, WithdrawalStatus::Processing], true)
                    && $payload->eventType->isWithdrawal()) {
                    app(WithdrawalProviderReferenceService::class)->bind($row, $gateway, $payload->providerReference, $payment);
                }
            } catch (FinancialException) {
                return null; // Safe generic binding refusal; no raw reference/account in the response.
            }
        }

        return $row;
    }

    private function canonicalGateway(string $gateway): string
    {
        return $gateway === 'manual' ? 'bank_transfer' : $gateway;
    }
}
