<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Exceptions\FinancialException;
use App\Exceptions\PayoutTransferException;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Withdrawal;
use Illuminate\Support\Facades\DB;

/** Redacted, read-only inspection. Existence of an index is not provider-authenticated evidence or a safe resend instruction. */
final class OutboundReferenceAuditService
{
    public function inspect(Payout|Withdrawal $row): array
    {
        $payout = $row instanceof Payout;
        $column = $payout ? 'payout_id' : 'withdrawal_id';
        $common = DB::table('outbound_provider_references')->where($column, $row->id)->first();
        $local = DB::table($payout ? 'payout_transfer_references' : 'withdrawal_provider_references')->where($column, $row->id)->first();
        $issues = [];
        $payments = Payment::withTrashed()->where('payable_type', $row::class)->where('payable_id', $row->id)->limit(3)->get();
        $payment = $payments->count() === 1 && ! $payments->first()->trashed() ? $payments->first() : null;
        if ($payments->count() > 1 || ($payments->isNotEmpty() && ! $payment)) {
            $issues[] = 'payment_history_requires_review';
        }
        $known = $payout ? [data_get($row->metadata, 'transfer.gateway_reference')]
            : [$row->provider_reference, data_get($row->metadata, 'outbound_dispatch.provider_reference')];
        $known = array_values(array_unique(array_filter(array_merge($known, $payments->pluck('gateway_reference')->all()), fn ($v) => is_string($v) && $v !== '')));
        if ($known === []) {
            $issues[] = 'no_known_reference_preimage';
        }
        if (count($known) > 1) {
            $issues[] = 'conflicting_reference_facts';
        }
        if ($known !== [] && ! $common) {
            $issues[] = 'common_reference_not_indexed';
        }
        if ($known !== [] && ! $local) {
            $issues[] = 'local_reference_not_indexed';
        }
        try {
            $accounts = app(WithdrawalProviderAccountService::class);
            $provider = $payout ? app(PayoutTransferGuard::class)->gateway($row) : $accounts->canonical($row->method->value);
            $scope = $payout ? data_get($row->metadata, 'transfer.account_scope') : $accounts->stored($row);
            if (! is_string($scope)) {
                throw FinancialException::withCode('invalid_account_context', 'Missing original account context.');
            }
            if ($scope !== $accounts->current($provider)) {
                $issues[] = 'account_not_active';
            }
            foreach ($known as $reference) {
                if ($local && ($local->reference_hash !== hash('sha256', $reference) || $local->account_scope !== $scope)) {
                    $issues[] = 'local_binding_mismatch';
                }
                try {
                    app(OutboundReferenceHistoryService::class)->assertAvailable($row, $accounts->canonical($provider), $scope, $reference, $payment);
                } catch (FinancialException) {
                    $issues[] = 'historical_ownership_conflict';
                }
                if ($common) {
                    try {
                        app(OutboundProviderReferenceService::class)->assertBound($row, $provider, $scope, $reference, $payment);
                    } catch (FinancialException) {
                        $issues[] = 'common_binding_mismatch';
                    }
                }
            }
        } catch (FinancialException|PayoutTransferException) {
            $issues[] = 'invalid_original_account_or_method';
        }

        return ['domain' => $payout ? 'payout' : 'withdrawal', 'obligation_id' => (int) $row->id,
            'status' => $row->status->value, 'soft_deleted' => $row->trashed(), 'common_indexed' => $common !== null,
            'local_indexed' => $local !== null, 'issues' => array_values(array_unique($issues)),
            'automatic_resend_allowed' => false, 'provider_authenticity_verified' => false];
    }
}
