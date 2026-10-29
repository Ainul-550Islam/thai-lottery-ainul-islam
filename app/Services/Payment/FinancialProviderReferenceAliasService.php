<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\Deposit;
use App\Models\Payment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * A bKash transaction ID is distinct from its checkout paymentID. Both share
 * the same provider/account namespace as outbound and primary references.
 * Callers must hold the original wallet, deposit, payment and attempt locks.
 */
final class FinancialProviderReferenceAliasService
{
    public const TABLE = 'financial_provider_reference_aliases';

    public const LOCKS = 'financial_provider_reference_locks';

    /** A replay key must include both identities, so a conflicting checkout reaches ownership validation. */
    public static function bkashEventId(string $paymentId, string $transactionId): string
    {
        return 'bkash_trx_'.hash('sha256', json_encode([$paymentId, $transactionId], JSON_THROW_ON_ERROR));
    }

    /** Serialize all writers for this provider/account/reference identity. */
    public function lockIdentity(string $key): void
    {
        if (DB::transactionLevel() < 1) {
            $this->refuse();
        }

        DB::table(self::LOCKS)->upsert(
            [['identity_key' => $key, 'created_at' => now()]],
            ['identity_key'],
            ['identity_key' => DB::raw('identity_key')],
        );
    }

    /** Prevent an ordinary inbound/outbound binding from taking an alias. */
    public function assertPrimaryCompatible(string $key, object $row, ?Payment $payment): void
    {
        $alias = DB::table(self::TABLE)->where('identity_key', $key)->lockForUpdate()->first();
        if ($alias && (! $row instanceof Deposit || (int) $alias->deposit_id !== (int) $row->id
            || ! $payment || (int) $alias->payment_id !== (int) $payment->id)) {
            $this->refuse();
        }
    }

    public function bind(Deposit $row, Payment $payment, object $attempt, string $reference): void
    {
        if (DB::transactionLevel() < 1) {
            $this->refuse();
        }

        DB::transaction(function () use ($row, $payment, $attempt, $reference): void {
            [$key, $scope] = $this->identity($row, $payment, $attempt, $reference);
            $this->lockIdentity($key);
            $primary = DB::table(FinancialProviderReferenceService::TABLE)->where('identity_key', $key)->lockForUpdate()->first();
            if ($primary && ((int) $primary->deposit_id !== (int) $row->id
                || (int) $primary->payment_id !== (int) $payment->id)) {
                $this->refuse();
            }

            try {
                DB::table(self::TABLE)->upsert([[
                    'identity_key' => $key,
                    'deposit_id' => $row->id,
                    'payment_id' => $payment->id,
                    'attempt_id' => $attempt->id,
                    'provider' => 'bkash',
                    'account_scope' => $scope,
                    'reference_hash' => hash('sha256', $reference),
                    'facts_hash' => app(FinancialProviderReferenceService::class)->fingerprint($row),
                    'created_at' => now(),
                ]], ['identity_key'], ['identity_key' => DB::raw('identity_key')]);
            } catch (UniqueConstraintViolationException) {
                $this->refuse();
            }
            $this->assertBound($row, $payment, $attempt, $reference);
            app(OutboundReferenceHistoryService::class)->assertAvailable($row, 'bkash', $scope, $reference, $payment);
        });
    }

    /** Replays must find the original immutable ownership; they cannot create it. */
    public function assertBound(Deposit $row, Payment $payment, object $attempt, string $reference): void
    {
        [$key, $scope] = $this->identity($row, $payment, $attempt, $reference);
        $owner = DB::table(self::TABLE)->where('identity_key', $key)->lockForUpdate()->first();
        if (! $owner || (int) $owner->deposit_id !== (int) $row->id
            || (int) $owner->payment_id !== (int) $payment->id || (int) $owner->attempt_id !== (int) $attempt->id
            || $owner->provider !== 'bkash' || $owner->account_scope !== $scope
            || ! hash_equals($owner->reference_hash, hash('sha256', $reference))
            || ! hash_equals($owner->facts_hash, app(FinancialProviderReferenceService::class)->fingerprint($row))) {
            $this->refuse();
        }
    }

    /** @return array{string, string} */
    private function identity(Deposit $row, Payment $payment, object $attempt, string $reference): array
    {
        $scope = app(DepositProviderAccountService::class)->assertActive($row);
        if ($row->trashed() || $payment->trashed() || $row->method->value !== 'bkash'
            || $payment->gateway !== 'bkash' || $payment->payable_type !== Deposit::class
            || (int) $payment->payable_id !== (int) $row->id || (int) $attempt->deposit_id !== (int) $row->id
            || (int) $attempt->payment_id !== (int) $payment->id || $attempt->provider !== 'bkash'
            || $attempt->account_scope !== $scope || $attempt->state !== 'projected') {
            $this->refuse();
        }

        return [app(OutboundProviderReferenceService::class)->identity('bkash', $scope, $reference), $scope];
    }

    private function refuse(): never
    {
        app(OutboundReferenceHistoryService::class)->refuse();
    }
}
