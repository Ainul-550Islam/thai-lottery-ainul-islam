<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Exceptions\FinancialException;
use App\Models\Deposit;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Withdrawal;
use Illuminate\Support\Facades\DB;

/** Read-only historical cross-domain refusal checks, including soft-deleted and unindexed owners. */
final class OutboundReferenceHistoryService
{
    public function assertAvailable(Deposit|Payout|Withdrawal $row, string $provider, string $scope, string $reference, ?Payment $payment): void
    {
        $accounts = app(WithdrawalProviderAccountService::class);
        $aliases = $provider === 'bank_transfer' ? ['bank_transfer', 'manual'] : [$provider];
        $hash = hash('sha256', $reference);
        foreach (['payout_transfer_references' => ['gateway', 'payout_id', Payout::class],
            'withdrawal_provider_references' => ['provider', 'withdrawal_id', Withdrawal::class]] as $table => [$column, $owner, $class]) {
            foreach (DB::table($table)->whereIn($column, $aliases)->where('account_scope', $scope)->where('reference_hash', $hash)->cursor() as $binding) {
                if ($row::class !== $class || (int) $binding->{$owner} !== (int) $row->id) {
                    $this->refuse();
                }
            }
        }
        foreach (DB::table(FinancialProviderReferenceService::TABLE)->where('identity_key', app(OutboundProviderReferenceService::class)->identity($provider, $scope, $reference))->cursor() as $binding) {
            $column = $row instanceof Deposit ? 'deposit_id' : ($row instanceof Payout ? 'payout_id' : 'withdrawal_id');
            if ((int) $binding->{$column} !== (int) $row->id) {
                $this->refuse();
            }
        }
        foreach (Deposit::withTrashed()->where(fn ($q) => $q->whereIn('method', $aliases)->orWhereIn('provider', $aliases))
            ->where('provider_reference', $reference)->cursor() as $other) {
            if ($other->provider_reference === $reference && app(DepositProviderAccountService::class)->stored($other) === $scope
                && (! $row instanceof Deposit || (int) $row->id !== (int) $other->id)) {
                $this->refuse();
            }
        }
        // Payment's broader gateway/reference UNIQUE remains conservative across account scopes and inbound domains.
        foreach (Payment::withTrashed()->whereIn('gateway', $aliases)->where('gateway_reference', $reference)->cursor() as $old) {
            if (! $payment || (int) $old->id !== (int) $payment->id || $old->trashed()
                || $old->payable_type !== $row::class || (int) $old->payable_id !== (int) $row->id) {
                $this->refuse();
            }
        }
        foreach (Withdrawal::withTrashed()->where(fn ($q) => $q->whereIn('method', $aliases)->orWhereIn('provider', $aliases))
            ->where(fn ($q) => $q->where('provider_reference', $reference)->orWhere('metadata->outbound_dispatch->provider_reference', $reference))->cursor() as $other) {
            if ($other->provider_reference !== $reference && data_get($other->metadata, 'outbound_dispatch.provider_reference') !== $reference) {
                continue;
            }
            if ($accounts->stored($other) === $scope && ($row::class !== Withdrawal::class || (int) $row->id !== (int) $other->id)) {
                $this->refuse();
            }
        }
        foreach (Payout::withTrashed()->where('metadata->transfer->gateway_reference', $reference)->cursor() as $other) {
            $record = $other->metadata['transfer'] ?? [];
            if (($record['gateway_reference'] ?? null) !== $reference || ($row::class === Payout::class && (int) $row->id === (int) $other->id)) {
                continue;
            }
            $method = $record['method'] ?? null;
            if (! in_array($method, ['bank_transfer', 'cheque'], true)) {
                $this->refuse(); // Known reference with indeterminate namespace cannot be reassigned.
            }
            if ($provider === 'bank_transfer' && ($record['account_scope'] ?? WithdrawalProviderAccountService::LEGACY) === $scope) {
                $this->refuse();
            }
        }
        if ($scope === WithdrawalProviderAccountService::LEGACY) {
            $keys = array_map(fn ($alias) => hash('sha256', json_encode([$alias, $reference], JSON_THROW_ON_ERROR)), $aliases);
            foreach (DB::table('withdrawal_reconciliation_references')->whereIn('provider_key', $keys)->cursor() as $old) {
                if ($row::class !== Withdrawal::class || (int) $row->id !== (int) $old->withdrawal_id) {
                    $this->refuse();
                }
            }
        }
    }

    public function refuse(): never
    {
        throw FinancialException::withCode('outbound_reference_ownership_conflict', 'Outbound reference ownership is incompatible or indeterminate; retain funds and review original evidence without resend or reassignment.');
    }
}
