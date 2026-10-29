<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Exceptions\FinancialException;
use App\Models\Payment;
use App\Models\Payout;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Retained prize-local binding followed by common cross-domain ownership in the same transaction. */
final class PayoutTransferReferenceService
{
    public function bind(Payout $row, Payment $payment, string $reference): void
    {
        if (DB::transactionLevel() < 1) {
            app(PayoutTransferGuard::class)->refuse($row);
        }
        DB::transaction(fn () => $this->bindWithin($row, $payment, $reference));
    }

    private function bindWithin(Payout $row, Payment $payment, string $reference): void
    {
        $guard = app(PayoutTransferGuard::class);
        if (DB::transactionLevel() < 1 || $reference === '' || trim($reference) !== $reference || strlen($reference) > 255
            || preg_match('/[\x00-\x1f\x7f]/', $reference) === 1) {
            $guard->refuse($row);
        }
        $boundPayment = $guard->payment($row, $row->metadata['transfer'] ?? []);
        if ((int) $boundPayment->id !== (int) $payment->id) {
            $guard->refuse($row);
        }
        $gateway = $guard->gateway($row);
        $scope = $row->metadata['transfer']['account_scope'] ?? null;
        if (! is_string($scope) || $scope !== $guard->account($row)) {
            $guard->refuse($row);
        }
        // The existing Payment schema has a broader gateway/reference UNIQUE
        // key (possibly case-insensitive). Refuse its collisions before posting,
        // including other payable domains; never weaken that storage invariant.
        if (Payment::withTrashed()->where('gateway', $gateway)->where('gateway_reference', $reference)
            ->where('id', '<>', $payment->id)->exists()) {
            $guard->refuse($row);
        }
        // Includes soft-deleted historical Payment owners; never silently chooses a duplicate winner.
        foreach (Payment::withTrashed()->where('payable_type', Payout::class)->where('gateway', $gateway)
            ->where('gateway_reference', $reference)->cursor() as $old) {
            if ($old->gateway_reference !== $reference) {
                continue; // SQL collation must not merge case-distinct provider references.
            }
            $other = Payout::withTrashed()->find($old->payable_id);
            if ((int) $old->payable_id !== (int) $row->id && (! $other || ($other->metadata['transfer']['account_scope'] ?? 'legacy-primary') === $scope)) {
                $guard->refuse($row);
            }
        }
        foreach (Payout::withTrashed()->where('metadata->transfer->gateway_reference', $reference)->cursor() as $old) {
            $record = $old->metadata['transfer'] ?? null;
            if ((int) $old->id !== (int) $row->id && is_array($record) && ($record['gateway_reference'] ?? null) === $reference
                && ($record['account_scope'] ?? 'legacy-primary') === $scope
                && ($record['method'] ?? null) === ($row->metadata['transfer']['method'] ?? null)) {
                $guard->refuse($row);
            }
        }
        $key = hash('sha256', json_encode([$gateway, $scope, $reference], JSON_THROW_ON_ERROR));
        // A single exclusive duplicate-key operation prevents shared-to-
        // exclusive lock-upgrade deadlocks when two contenders arrive together.
        try {
            DB::table('payout_transfer_references')->upsert([['identity_key' => $key, 'payout_id' => $row->id,
                'payment_id' => $payment->id, 'gateway' => $gateway, 'account_scope' => $scope,
                'reference_hash' => hash('sha256', $reference), 'created_at' => now()]], ['identity_key'], ['identity_key' => DB::raw('identity_key')]);
        } catch (UniqueConstraintViolationException) {
            $guard->refuse($row);
        }
        $owner = DB::table('payout_transfer_references')->where('identity_key', $key)->lockForUpdate()->first();
        if (! $owner || (int) $owner->payout_id !== (int) $row->id || (int) $owner->payment_id !== (int) $payment->id
            || $owner->gateway !== $gateway || $owner->account_scope !== $scope || $owner->reference_hash !== hash('sha256', $reference)) {
            $guard->refuse($row);
        }
        try {
            app(OutboundProviderReferenceService::class)->bind($row, $gateway, $scope, $reference, $payment);
        } catch (FinancialException) {
            $guard->refuse($row);
        }

    }
}
