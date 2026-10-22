<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\FinancialTransactionType;
use App\Enums\WalletHoldType;
use App\Models\FinancialTransaction;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Payment\WithdrawalDisbursementService;
use Illuminate\Support\Facades\DB;

/** Non-expiring ownership of withdrawal holds. Caller owns WALLET -> WITHDRAWAL locks. */
final class WithdrawalReservationService
{
    public function __construct(private readonly WalletHoldService $holds, private readonly WithdrawalIntegrityGuard $guard) {}

    public function exists(Withdrawal $row): bool
    {
        return DB::table('withdrawal_reservations')->where('withdrawal_id', $row->id)->exists();
    }

    public function reserve(Withdrawal $row, Wallet $wallet): Wallet
    {
        $this->boundary();

        return DB::transaction(function () use ($row, $wallet): Wallet {
            $this->guard->assertTerms($row, $wallet);
            if ($this->exists($row)) {
                $this->guard->refuse();
            }
            app(StripeRefundWithdrawalGuard::class)->assertAllowed($wallet);
            $initial = app(WithdrawalInitialHoldService::class);
            $initial->assertRequiredExists($row, $wallet);
            $initialId = null;
            if ($initial->exists($row)) {
                $initialId = $initial->transfer($row, $wallet);
                $updated = $wallet;
            } else {
                $updated = $this->holds->hold($wallet, Money::fromDatabase((string) $row->amount, $row->currency), WalletHoldType::Withdrawal);
            }
            DB::table('withdrawal_reservations')->insert(['withdrawal_id' => $row->id, 'wallet_id' => $wallet->id,
                'user_id' => $row->user_id, 'currency' => $row->currency->value, 'amount' => (string) $row->amount,
                'terms_hash' => $this->guard->termsFingerprint($row), 'state' => 'active', 'created_at' => now(),
                'initial_hold_id' => $initialId]);
            $this->assertActive($row, $updated);

            return $updated;
        });
    }

    public function assertActive(Withdrawal $row, Wallet $wallet): void
    {
        $record = $this->owned($row, $wallet);
        if ($record->state !== 'active' || $record->financial_transaction_id !== null || $record->closed_at !== null) {
            $this->guard->refuse();
        }
        app(WithdrawalHoldFloor::class)->assertCovered($wallet);
    }

    /**
     * One savepoint owns the entire close -> unlock -> canonical debit -> proof unit.
     * No public consume-only or mark-consumed-only half-step remains.
     * Options are descriptive metadata only; identity, economics and provenance are server-owned.
     */
    public function settle(Withdrawal $row, Wallet $wallet, array $options = []): FinancialTransaction
    {
        $this->boundary();

        return DB::transaction(function () use ($row, $wallet, $options): FinancialTransaction {
            $this->assertActive($row, $wallet);
            $key = app(IdempotencyService::class)->deterministicKey('withdrawal-debit', (string) $row->id);
            if (FinancialTransaction::withTrashed()->where('idempotency_key', $key)->lockForUpdate()->first(['id']) !== null) {
                $this->guard->refuse();
            }
            // This intermediate closed owner is invisible outside the SQL transaction.
            // It grants no generic exclusion or caller-selected release capability.
            DB::table('withdrawal_reservations')->where('withdrawal_id', $row->id)->update([
                'state' => 'consumed', 'closed_at' => now(),
            ]);
            app(WithdrawalInitialHoldService::class)->closeTransferred($row, $wallet, 'consumed');
            $amount = Money::fromDatabase((string) $row->amount, $row->currency);
            $unlocked = $this->holds->consume($wallet, $amount);
            $tx = app(WalletService::class)->debit($unlocked, $amount, FinancialTransactionType::Withdrawal, $key, [
                'description' => isset($options['description']) && is_string($options['description'])
                    ? $options['description'] : sprintf('Withdrawal %s settled', $row->reference_number),
                'fee' => (string) $row->fee, 'reference_type' => Withdrawal::class, 'reference_id' => (int) $row->id,
                'metadata' => array_merge(is_array($options['metadata'] ?? null) ? $options['metadata'] : [], [
                    'withdrawal_id' => (int) $row->id, 'withdrawal_reference' => $row->reference_number,
                    'withdrawal_net_amount' => (string) $row->net_amount, 'reservation_consumed' => $amount->toString(),
                ]),
            ]);
            DB::table('withdrawal_reservations')->where('withdrawal_id', $row->id)->update(['financial_transaction_id' => $tx->id]);
            $this->guard->assertProof($row, $wallet, $tx, $key);

            return $tx;
        });
    }

    public function assertConsumed(Withdrawal $row, Wallet $wallet, FinancialTransaction $tx): void
    {
        $record = $this->owned($row, $wallet);
        if ($record->state !== 'consumed' || (int) $record->financial_transaction_id !== (int) $tx->id || $record->closed_at === null) {
            $this->guard->refuse();
        }
    }

    public function assertReleased(Withdrawal $row, Wallet $wallet): void
    {
        $record = $this->owned($row, $wallet);
        if ($record->state !== 'released' || $record->financial_transaction_id !== null || $record->closed_at === null) {
            $this->guard->refuse();
        }
    }

    /** verifiedFinalFailure is for authenticated adapter / independent evidence callers, never request input. */
    public function release(Withdrawal $row, Wallet $wallet, bool $verifiedFinalFailure = false): array
    {
        $this->boundary();

        return DB::transaction(function () use ($row, $wallet, $verifiedFinalFailure): array {
            $this->assertActive($row, $wallet);
            $claim = ($row->metadata ?? [])[WithdrawalDisbursementService::CLAIM] ?? null;
            if ($claim !== null && ! $verifiedFinalFailure) {
                $this->guard->refuse();
            }
            // Only the verified owner closes itself, before the central mechanical
            // floor checks the remaining owners. Both writes have one savepoint.
            DB::table('withdrawal_reservations')->where('withdrawal_id', $row->id)->update(['state' => 'released', 'closed_at' => now()]);
            app(WithdrawalInitialHoldService::class)->closeTransferred($row, $wallet, 'released');
            $updated = $this->holds->release($wallet, Money::fromDatabase((string) $row->amount, $row->currency));

            return ['wallet' => $updated, 'released' => true];
        });
    }

    private function owned(Withdrawal $row, Wallet $wallet): object
    {
        $this->guard->assertTerms($row, $wallet);
        $query = DB::table('withdrawal_reservations')->where('withdrawal_id', $row->id)
            ->selectRaw('*, CAST(amount AS CHAR) AS exact_amount');
        $record = (DB::transactionLevel() > 0 ? $query->lockForUpdate() : $query)->first();
        if (! $record || (int) $record->wallet_id !== (int) $wallet->id || (int) $record->user_id !== (int) $row->user_id
            || $record->currency !== $row->currency->value || bccomp($record->exact_amount, (string) $row->amount, 2) !== 0
            || ! hash_equals($record->terms_hash, $this->guard->termsFingerprint($row))) {
            $this->guard->refuse();
        }

        app(WithdrawalInitialHoldService::class)->assertCanonical($row, $wallet, $record);

        return $record;
    }

    private function boundary(): void
    {
        if (DB::transactionLevel() < 1) {
            $this->guard->refuse();
        }
    }
}
