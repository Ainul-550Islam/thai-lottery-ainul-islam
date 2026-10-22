<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Exceptions\FinancialException;
use App\Models\Payment;
use App\Models\Withdrawal;
use Illuminate\Support\Facades\DB;

/** Shared SQL ownership for known withdrawal references, across all covered finalization lanes. */
final class WithdrawalProviderReferenceService
{
    public function __construct(private readonly WithdrawalProviderAccountService $accounts) {}

    /** Caller owns wallet -> withdrawal locks; this takes Payment -> shared identity locks. No network or wallet write. */
    public function bind(Withdrawal $row, string $provider, string $reference, ?Payment $payment = null): int
    {
        if (DB::transactionLevel() < 1) {
            throw $this->conflict();
        }

        // A binding caller may catch a refusal inside its own SQL transaction (webhooks).
        // The local and common writes must roll back together even in that case.
        return DB::transaction(fn () => $this->bindWithin($row, $provider, $reference, $payment));
    }

    private function bindWithin(Withdrawal $row, string $provider, string $reference, ?Payment $payment): int
    {
        if (DB::transactionLevel() < 1 || trim($reference) !== $reference || $reference === '' || strlen($reference) > 255
            || preg_match('/[\x00-\x1f\x7f]/', $reference) === 1) {
            throw $this->conflict();
        }
        $provider = $this->accounts->canonical($provider);
        $scope = $this->accounts->assertActive($row);
        if ($provider !== $this->accounts->canonical($row->method->value)
            || ($row->provider && $provider !== $this->accounts->canonical($row->provider))
            || ($row->provider_reference !== null && $row->provider_reference !== $reference)) {
            throw $this->conflict();
        }
        $payments = Payment::query()->where('payable_type', Withdrawal::class)->where('payable_id', $row->id)->lockForUpdate()->limit(2)->get();
        if ($payments->count() > 1 || ($payment !== null && $payments->first()?->id !== $payment->id)) {
            throw $this->conflict();
        }
        $payment = $payments->first();
        if ($payment && ((int) $payment->user_id !== (int) $row->user_id || $payment->currency !== $row->currency
            || $this->accounts->canonical($payment->method->value) !== $provider || $this->accounts->canonical($payment->gateway) !== $provider
            || bccomp((string) $payment->amount, (string) $row->amount, 2) !== 0 || bccomp((string) $payment->fee, (string) $row->fee, 2) !== 0
            || ($payment->gateway_reference !== null && $payment->gateway_reference !== $reference))) {
            throw $this->conflict();
        }
        $this->assertHistoricalOwnership($row, $provider, $scope, $reference);
        $identity = hash('sha256', json_encode([$provider, $scope, $reference], JSON_THROW_ON_ERROR));
        DB::table('withdrawal_provider_references')->insertOrIgnore([
            'identity_key' => $identity, 'withdrawal_id' => $row->id, 'payment_id' => $payment?->id,
            'provider' => $provider, 'account_scope' => $scope, 'reference_hash' => hash('sha256', $reference), 'created_at' => now(),
        ]);
        $owner = DB::table('withdrawal_provider_references')->where('identity_key', $identity)->lockForUpdate()->first();
        if (! $owner || (int) $owner->withdrawal_id !== (int) $row->id || $owner->provider !== $provider || $owner->account_scope !== $scope
            || $owner->reference_hash !== hash('sha256', $reference)
            || ($owner->payment_id !== null && $payment !== null && (int) $owner->payment_id !== (int) $payment->id)) {
            throw $this->conflict();
        }
        if ($owner->payment_id === null && $payment !== null) {
            DB::table('withdrawal_provider_references')->where('id', $owner->id)->update(['payment_id' => $payment->id]);
        }

        app(OutboundProviderReferenceService::class)->bind($row, $provider, $scope, $reference, $payment);

        return (int) $owner->id;
    }

    /** Completed replay must prove the existing identity; never call bind() to manufacture missing evidence. */
    public function assertBound(Withdrawal $row, ?Payment $payment): void
    {
        if ($row->provider_reference === null) {
            if ($payment !== null || DB::table('withdrawal_provider_references')->where('withdrawal_id', $row->id)->exists()
                || DB::table('outbound_provider_references')->where('withdrawal_id', $row->id)->exists()
                || DB::table(FinancialProviderReferenceService::TABLE)->where('withdrawal_id', $row->id)->exists()) {
                throw $this->conflict();
            }

            return; // Unreferenced local/manual accounting is not authenticated external settlement.
        }
        $provider = $this->accounts->canonical($row->method->value);
        $scope = $this->accounts->stored($row);
        $reference = $row->provider_reference;
        $key = app(OutboundProviderReferenceService::class)->identity($provider, $scope, $reference);
        $owner = DB::table('withdrawal_provider_references')->where('withdrawal_id', $row->id)->first();
        if (! $owner || $owner->identity_key !== $key || $owner->provider !== $provider || $owner->account_scope !== $scope
            || $owner->reference_hash !== hash('sha256', $reference)
            || ($payment === null ? $owner->payment_id !== null : (int) $owner->payment_id !== (int) $payment->id)) {
            throw $this->conflict();
        }
        $this->assertHistoricalOwnership($row, $provider, $scope, $reference);
        app(OutboundProviderReferenceService::class)->assertBound($row, $provider, $scope, $reference, $payment);
    }

    /** Read-only bounded per-obligation inspection; never calls bind(), decrypts a destination or resets ownership. */
    public function inspect(Withdrawal $row): array
    {
        $issues = [];
        $provider = null;
        $scope = null;
        $owner = DB::table('withdrawal_provider_references')->where('withdrawal_id', $row->id)->first();
        try {
            $provider = $this->accounts->canonical($row->method->value);
            $scope = $this->accounts->stored($row);
            if ($scope !== $this->accounts->current($row->method->value)) {
                $issues[] = 'account_not_active';
            }
            $payments = Payment::withTrashed()->where('payable_type', Withdrawal::class)->where('payable_id', $row->id)->limit(3)->get();
            if ($payments->count() > 1) {
                $issues[] = 'multiple_payment_rows_require_review';
            }
            $references = array_values(array_unique(array_filter(array_merge([$row->provider_reference], $payments->pluck('gateway_reference')->all()), fn ($v) => is_string($v) && $v !== '')));
            if ($references === []) {
                $issues[] = 'no_known_provider_reference';
            }
            if (count($references) > 1) {
                $issues[] = 'conflicting_reference_facts';
            }
            foreach ($references as $reference) {
                try {
                    $this->assertHistoricalOwnership($row, $provider, $scope, $reference);
                } catch (FinancialException) {
                    $issues[] = 'historical_ownership_conflict';
                }
                if ($owner === null) {
                    $issues[] = 'historical_reference_not_indexed';
                } elseif ($owner->identity_key !== hash('sha256', json_encode([$provider, $scope, $reference], JSON_THROW_ON_ERROR))) {
                    $issues[] = 'shared_binding_mismatch';
                }
            }
        } catch (FinancialException) {
            $issues[] = 'invalid_account_context';
        }

        $issues = array_merge($issues, app(OutboundReferenceAuditService::class)->inspect($row)['issues']);

        return ['withdrawal_id' => (int) $row->id, 'withdrawal_status' => $row->status->value,
            'provider' => $provider, 'account_scope' => $scope, 'indexed' => $owner !== null,
            'issues' => array_values(array_unique($issues)), 'automatic_resend_allowed' => false];
    }

    private function assertHistoricalOwnership(Withdrawal $row, string $provider, string $scope, string $reference): void
    {
        $methods = $provider === 'bank_transfer' ? ['bank_transfer', 'manual'] : [$provider];
        foreach (Withdrawal::withTrashed()->whereIn('method', $methods)->where('provider_reference', $reference)->cursor() as $other) {
            if ($other->provider_reference === $reference && (int) $other->id !== (int) $row->id && $this->accounts->stored($other) === $scope) {
                throw $this->conflict();
            }
        }
        foreach (Payment::withTrashed()->where('payable_type', Withdrawal::class)
            ->where(fn ($q) => $q->where('payable_id', $row->id)
                ->orWhere(fn ($foreign) => $foreign->whereIn('gateway', $methods)->where('gateway_reference', $reference)))->cursor() as $old) {
            if ((int) $old->payable_id === (int) $row->id) {
                if ((int) $old->user_id !== (int) $row->user_id || $old->currency !== $row->currency
                    || $this->accounts->canonical($old->method->value) !== $provider || $this->accounts->canonical($old->gateway) !== $provider
                    || bccomp((string) $old->amount, (string) $row->amount, 2) !== 0 || bccomp((string) $old->fee, (string) $row->fee, 2) !== 0
                    || ($old->gateway_reference !== null && $old->gateway_reference !== $reference)) {
                    throw $this->conflict();
                }
            } elseif ($old->gateway_reference === $reference) {
                $other = Withdrawal::withTrashed()->find($old->payable_id);
                if (! $other || $this->accounts->stored($other) === $scope) {
                    throw $this->conflict();
                }
            }
        }
        // Existing Part28 bindings have no account column and are legacy-primary.
        if ($scope === WithdrawalProviderAccountService::LEGACY) {
            $old = DB::table('withdrawal_reconciliation_references')
                ->where('provider_key', hash('sha256', json_encode([$provider, $reference], JSON_THROW_ON_ERROR)))->first();
            if ($old && (int) $old->withdrawal_id !== (int) $row->id) {
                throw $this->conflict();
            }
        }
    }

    private function conflict(): FinancialException
    {
        return FinancialException::withCode('withdrawal_provider_reference_conflict', 'Known withdrawal reference has incompatible ownership, provider, account or Payment facts; retain funds for reconciliation.');
    }
}
