<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\Deposit;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Withdrawal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Conservative single owner across covered inbound/outbound lanes. No guessing of provider-specific direction namespaces. */
final class FinancialProviderReferenceService
{
    public const TABLE = 'financial_provider_reference_owners';

    public function fingerprint(Deposit|Payout|Withdrawal $row): string
    {
        return hash('sha256', json_encode([$row::class, (int) $row->id, $row->reference_number, (int) $row->user_id,
            (int) $row->wallet_id, $row->currency->value, (string) $row->amount,
            $row instanceof Deposit ? [(string) $row->fee, (string) $row->net_amount, $row->method->value] : null], JSON_THROW_ON_ERROR));
    }

    /** Caller must already own the original wallet/obligation and any Payment locks. Savepoint-atomic on refusal. */
    public function bind(Deposit|Payout|Withdrawal $row, string $provider, string $scope, string $reference, ?Payment $payment): int
    {
        if (DB::transactionLevel() < 1 || $row->trashed()) {
            $this->refuse();
        }

        return DB::transaction(function () use ($row, $provider, $scope, $reference, $payment): int {
            $key = app(OutboundProviderReferenceService::class)->identity($provider, $scope, $reference);
            $provider = app(WithdrawalProviderAccountService::class)->canonical($provider);
            // Primary references and transaction aliases share one namespace.
            // The common identity lock closes the cross-table insert race.
            $aliases = app(FinancialProviderReferenceAliasService::class);
            $aliases->lockIdentity($key);
            $aliases->assertPrimaryCompatible($key, $row, $payment);
            try {
                DB::table(self::TABLE)->upsert([['identity_key' => $key, 'deposit_id' => $row instanceof Deposit ? $row->id : null,
                    'withdrawal_id' => $row instanceof Withdrawal ? $row->id : null, 'payout_id' => $row instanceof Payout ? $row->id : null,
                    'payment_id' => $payment?->id, 'provider' => $provider, 'account_scope' => $scope,
                    'reference_hash' => hash('sha256', $reference), 'facts_hash' => $this->fingerprint($row), 'created_at' => now()]],
                    ['identity_key'], ['identity_key' => DB::raw('identity_key')]);
            } catch (UniqueConstraintViolationException) {
                $this->refuse();
            }
            $owner = DB::table(self::TABLE)->where('identity_key', $key)->lockForUpdate()->first();
            $this->verify($owner, $row, $provider, $scope, $reference, $payment);
            app(OutboundReferenceHistoryService::class)->assertAvailable($row, $provider, $scope, $reference, $payment);
            if ($owner->payment_id === null && $payment) {
                DB::table(self::TABLE)->where('id', $owner->id)->update(['payment_id' => $payment->id]);
            }

            return (int) $owner->id;
        });
    }

    /** Projection/replay cannot manufacture or repair historical ownership. */
    public function assertBound(Deposit|Payout|Withdrawal $row, string $provider, string $scope, string $reference, ?Payment $payment): void
    {
        $key = app(OutboundProviderReferenceService::class)->identity($provider, $scope, $reference);
        $query = DB::table(self::TABLE)->where('identity_key', $key);
        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate(); // replay requires the current durable identity
        }
        $owner = $query->first();
        $this->verify($owner, $row, app(WithdrawalProviderAccountService::class)->canonical($provider), $scope, $reference, $payment);
        if (($owner->payment_id === null) !== ($payment === null)) {
            $this->refuse();
        }
    }

    private function verify(?object $owner, Deposit|Payout|Withdrawal $row, string $provider, string $scope, string $reference, ?Payment $payment): void
    {
        $column = $row instanceof Deposit ? 'deposit_id' : ($row instanceof Payout ? 'payout_id' : 'withdrawal_id');
        if (! $owner || (int) $owner->{$column} !== (int) $row->id || $owner->provider !== $provider || $owner->account_scope !== $scope
            || $owner->reference_hash !== hash('sha256', $reference) || $owner->facts_hash !== $this->fingerprint($row)
            || ($owner->payment_id !== null && (int) $owner->payment_id !== (int) $payment?->id)
            || ($payment && ($payment->trashed() || $payment->payable_type !== $row::class || (int) $payment->payable_id !== (int) $row->id))) {
            $this->refuse();
        }
    }

    private function refuse(): never
    {
        app(OutboundReferenceHistoryService::class)->refuse();
    }
}
