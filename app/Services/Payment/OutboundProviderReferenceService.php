<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\Payment;
use App\Models\Payout;
use App\Models\Withdrawal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** One immutable SQL owner across covered prize/withdrawal lanes. No provider call, money write or historical backfill. */
final class OutboundProviderReferenceService
{
    public function identity(string $provider, string $scope, string $reference): string
    {
        $provider = app(WithdrawalProviderAccountService::class)->canonical($provider);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,79}$/D', $scope) !== 1
            || $reference === '' || trim($reference) !== $reference || strlen($reference) > 255 || preg_match('/[\x00-\x1f\x7f]/', $reference)) {
            app(OutboundReferenceHistoryService::class)->refuse();
        }

        return hash('sha256', json_encode([$provider, $scope, $reference], JSON_THROW_ON_ERROR));
    }

    /** Caller owns wallet/obligation, Payment and local-index locks, in that order. */
    public function bind(Payout|Withdrawal $row, string $provider, string $scope, string $reference, ?Payment $payment): int
    {
        if (DB::transactionLevel() < 1) {
            app(OutboundReferenceHistoryService::class)->refuse();
        }

        return DB::transaction(fn () => $this->bindWithin($row, $provider, $scope, $reference, $payment));
    }

    private function bindWithin(Payout|Withdrawal $row, string $provider, string $scope, string $reference, ?Payment $payment): int
    {
        $history = app(OutboundReferenceHistoryService::class);
        if (DB::transactionLevel() < 1 || $row->trashed()) {
            $history->refuse();
        }
        $key = $this->identity($provider, $scope, $reference);
        $provider = app(WithdrawalProviderAccountService::class)->canonical($provider);
        $column = $row instanceof Payout ? 'payout_id' : 'withdrawal_id';
        $table = $row instanceof Payout ? 'payout_transfer_references' : 'withdrawal_provider_references';
        $local = DB::table($table)->where($column, $row->id)->first();
        if (! $local || app(WithdrawalProviderAccountService::class)->canonical($local->gateway ?? $local->provider) !== $provider
            || $local->account_scope !== $scope || $local->reference_hash !== hash('sha256', $reference)
            || ($local->payment_id === null ? $payment !== null : (int) $local->payment_id !== (int) $payment?->id)
            || ($payment && ($payment->trashed() || $payment->payable_type !== $row::class || (int) $payment->payable_id !== (int) $row->id))) {
            $history->refuse();
        }
        // A no-op upsert takes an exclusive duplicate-key lock directly; INSERT IGNORE
        // followed by FOR UPDATE can deadlock on two shared-to-exclusive upgrades.
        try {
            DB::table('outbound_provider_references')->upsert([['identity_key' => $key,
                'payout_id' => $row instanceof Payout ? $row->id : null, 'withdrawal_id' => $row instanceof Withdrawal ? $row->id : null,
                'payment_id' => $payment?->id, 'provider' => $provider, 'account_scope' => $scope,
                'reference_hash' => hash('sha256', $reference), 'created_at' => now()]], ['identity_key'], ['identity_key' => DB::raw('identity_key')]);
        } catch (UniqueConstraintViolationException) {
            $history->refuse();
        }
        $owner = DB::table('outbound_provider_references')->where('identity_key', $key)->lockForUpdate()->first();
        $this->verify($owner, $row, $provider, $scope, $reference, $payment);
        // Ownership is decisive before scans; historical ambiguity rolls the whole caller transaction back.
        app(FinancialProviderReferenceService::class)->bind($row, $provider, $scope, $reference, $payment);
        $history->assertAvailable($row, $provider, $scope, $reference, $payment);
        if ($owner->payment_id === null && $payment) {
            DB::table('outbound_provider_references')->where('id', $owner->id)->update(['payment_id' => $payment->id]);
        }

        return (int) $owner->id;
    }

    /** Read-only canonical projection check; never creates or repairs an index. */
    public function assertBound(Payout|Withdrawal $row, string $provider, string $scope, string $reference, ?Payment $payment): void
    {
        $key = $this->identity($provider, $scope, $reference);
        $query = DB::table('outbound_provider_references')->where('identity_key', $key);
        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate(); // current owner after any earlier snapshot/wallet wait
        }
        $owner = $query->first();
        $this->verify($owner, $row, app(WithdrawalProviderAccountService::class)->canonical($provider), $scope, $reference, $payment);
        app(FinancialProviderReferenceService::class)->assertBound($row, $provider, $scope, $reference, $payment);
        if ($payment && (int) $owner->payment_id !== (int) $payment->id) {
            app(OutboundReferenceHistoryService::class)->refuse();
        }
    }

    private function verify(?object $owner, Payout|Withdrawal $row, string $provider, string $scope, string $reference, ?Payment $payment): void
    {
        if (! $owner || $owner->provider !== $provider || $owner->account_scope !== $scope || $owner->reference_hash !== hash('sha256', $reference)
            || ($row instanceof Payout ? ((int) $owner->payout_id !== (int) $row->id || $owner->withdrawal_id !== null)
                : ((int) $owner->withdrawal_id !== (int) $row->id || $owner->payout_id !== null))
            || ($owner->payment_id !== null && (int) $owner->payment_id !== (int) $payment?->id)) {
            app(OutboundReferenceHistoryService::class)->refuse();
        }
    }
}
