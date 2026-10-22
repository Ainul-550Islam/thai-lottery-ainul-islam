<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\WalletHoldType;
use App\Enums\WithdrawalStatus;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Payment\WithdrawalDisbursementService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Fresh, non-expiring initial hold ownership; never inferred from wallet totals. */
final class WithdrawalInitialHoldService
{
    public const TABLE = 'withdrawal_initial_holds';

    public const ORIGIN_KEY = 'withdrawal_initial_hold_origin';

    public function assertRequiredExists(Withdrawal $row, Wallet $wallet): void
    {
        $this->guard->assertTerms($row, $wallet);
        if (array_key_exists(self::ORIGIN_KEY, $row->metadata ?? [])
            && (data_get($row->metadata, self::ORIGIN_KEY) !== 'v1' || ! $this->exists($row))) {
            $this->guard->refuse();
        }
    }

    public function __construct(private readonly WithdrawalIntegrityGuard $guard, private readonly WalletHoldService $holds) {}

    public function exists(Withdrawal $row): bool
    {
        $query = DB::table(self::TABLE)->where('withdrawal_id', $row->id)->select('id');

        return (DB::transactionLevel() > 0 ? $query->lockForUpdate() : $query)->first() !== null;
    }

    /** Caller retains the original request transaction and wallet-first locks. */
    public function reserveNewRequest(Withdrawal $row, Wallet $wallet): Wallet
    {
        $this->boundary();

        return DB::transaction(function () use ($row, $wallet): Wallet {
            $wallet = app(WalletLockService::class)->lock((int) $wallet->id);
            $current = app(FinancialStateTransitionService::class)->lockWithdrawal($row);
            $this->guard->assertTerms($current, $wallet);
            if (data_get($current->metadata, self::ORIGIN_KEY) !== 'v1' || ! $row->wasRecentlyCreated || $current->status !== WithdrawalStatus::Pending
                || $this->exists($current) || app(WithdrawalReservationService::class)->exists($current)
                || ! is_string(data_get($current->metadata, WithdrawalIntegrityGuard::TERMS_KEY))) {
                $this->guard->refuse();
            }
            app(StripeRefundWithdrawalGuard::class)->assertAllowed($wallet);
            $amount = Money::fromDatabase((string) $current->amount, $current->currency);
            $updated = $this->holds->hold($wallet, $amount, WalletHoldType::Withdrawal);
            $header = ['withdrawal_id' => (int) $current->id, 'wallet_id' => (int) $wallet->id,
                'user_id' => (int) $current->user_id, 'currency' => $current->currency->value,
                'amount' => $amount->toString(), 'terms_hash' => $this->guard->termsFingerprint($current)];
            DB::table(self::TABLE)->insert(array_merge($header, [
                'origin_cipher' => Crypt::encryptString(json_encode($header, JSON_THROW_ON_ERROR)),
                'state' => 'held', 'created_at' => now(),
            ]));
            app(WithdrawalHoldFloor::class)->assertCovered($updated);

            return $updated;
        });
    }

    /** No unlock/relock: the canonical owner is inserted in this same savepoint. */
    public function transfer(Withdrawal $row, Wallet $wallet): int
    {
        $this->boundary();
        $record = $this->owned($row, $wallet);
        if ($record->state !== 'held' || $record->transferred_at !== null || $record->closed_at !== null
            || app(WithdrawalReservationService::class)->exists($row)) {
            $this->guard->refuse();
        }
        app(WithdrawalHoldFloor::class)->assertCovered($wallet);
        DB::table(self::TABLE)->where('id', $record->id)->update(['state' => 'transferred', 'transferred_at' => now()]);

        return (int) $record->id;
    }

    /** Close only after the canonical owner has closed itself, before wallet mechanics. */
    public function closeTransferred(Withdrawal $row, Wallet $wallet, string $state): void
    {
        if (! $this->exists($row)) {
            return;
        }
        $this->boundary();
        $record = $this->owned($row, $wallet);
        $canonical = DB::table('withdrawal_reservations')->where('withdrawal_id', $row->id)->lockForUpdate()->first();
        if (! in_array($state, ['consumed', 'released'], true) || $record->state !== 'transferred'
            || $record->transferred_at === null || $record->closed_at !== null
            || ! $canonical || (int) $canonical->initial_hold_id !== (int) $record->id || $canonical->state !== $state) {
            $this->guard->refuse();
        }
        DB::table(self::TABLE)->where('id', $record->id)->update(['state' => $state, 'closed_at' => now()]);
    }

    public function releasePending(Withdrawal $row, Wallet $wallet): array
    {
        $this->boundary();

        return DB::transaction(function () use ($row, $wallet): array {
            $record = $this->owned($row, $wallet);
            if ($record->state !== 'held' || $record->transferred_at !== null || $record->closed_at !== null
                || ! in_array($row->status, [WithdrawalStatus::Pending, WithdrawalStatus::UnderReview, WithdrawalStatus::KycRequired], true)
                || app(WithdrawalReservationService::class)->exists($row)
                || data_get($row->metadata, WithdrawalDisbursementService::CLAIM) !== null) {
                $this->guard->refuse();
            }
            app(WithdrawalHoldFloor::class)->assertCovered($wallet);
            DB::table(self::TABLE)->where('id', $record->id)->update(['state' => 'released', 'closed_at' => now()]);
            $updated = $this->holds->release($wallet, Money::fromDatabase((string) $row->amount, $row->currency));

            return ['wallet' => $updated, 'released' => true];
        });
    }

    /** Called by canonical proof even after finality. Missing or mismatched links never repair themselves. */
    public function assertCanonical(Withdrawal $row, Wallet $wallet, object $canonical): void
    {
        $this->assertRequiredExists($row, $wallet);
        if ($canonical->initial_hold_id === null) {
            if ($this->exists($row)) {
                $this->guard->refuse();
            }

            return;
        }
        $record = $this->owned($row, $wallet);
        $expected = ['active' => 'transferred', 'consumed' => 'consumed', 'released' => 'released'][$canonical->state] ?? null;
        if ((int) $canonical->initial_hold_id !== (int) $record->id || $expected === null || $record->state !== $expected
            || $record->transferred_at === null || (($record->closed_at === null) !== ($canonical->state === 'active'))) {
            $this->guard->refuse();
        }
    }

    /** Replay can observe old ownerless requests but can never enroll them. */
    public function assertReplay(Withdrawal $row, Wallet $wallet): void
    {
        $this->assertRequiredExists($row, $wallet);
        if (! $this->exists($row)) {
            return;
        }
        $record = $this->owned($row, $wallet);
        if ($record->state === 'held') {
            if ($record->transferred_at !== null || $record->closed_at !== null
                || ! in_array($row->status, [WithdrawalStatus::Pending, WithdrawalStatus::UnderReview, WithdrawalStatus::KycRequired], true)
                || app(WithdrawalReservationService::class)->exists($row)) {
                $this->guard->refuse();
            }
            app(WithdrawalHoldFloor::class)->assertCovered($wallet);

            return;
        }
        if ($record->state === 'released' && $record->transferred_at === null) {
            if (! $row->status->requiresHoldRelease() || $record->closed_at === null || app(WithdrawalReservationService::class)->exists($row)) {
                $this->guard->refuse();
            }

            return;
        }
        $canonical = DB::table('withdrawal_reservations')->where('withdrawal_id', $row->id)->lockForUpdate()->first();
        if (! $canonical) {
            $this->guard->refuse();
        }
        $this->assertCanonical($row, $wallet, $canonical);
        if (($canonical->state === 'active' && ! $row->status->holdsReservedFunds())
            || ($canonical->state === 'released' && ! $row->status->requiresHoldRelease())
            || ($canonical->state === 'consumed' && ($row->status !== WithdrawalStatus::Completed
                || (int) $row->financial_transaction_id !== (int) $canonical->financial_transaction_id))) {
            $this->guard->refuse();
        }
        app(WithdrawalHoldFloor::class)->assertCovered($wallet);
    }

    /** Add held originals; transferred originals MUST have exactly one matching active canonical owner. */
    public function minimum(Wallet $wallet): string
    {
        $this->boundary();
        $total = '0.00';
        foreach (DB::table(self::TABLE)->where('wallet_id', $wallet->id)->whereIn('state', ['held', 'transferred'])
            ->orderBy('id')->lockForUpdate()->get() as $record) {
            $this->assertHeader($record, $wallet);
            if ($record->closed_at !== null) {
                $this->guard->refuse();
            }
            $canonical = DB::table('withdrawal_reservations')->where('withdrawal_id', $record->withdrawal_id)->lockForUpdate()->first();
            if ($record->state === 'held') {
                if ($record->transferred_at !== null || $canonical !== null) {
                    $this->guard->refuse();
                }
                $total = bcadd($total, (string) $record->amount, 2);
            } elseif ($record->transferred_at === null || ! $canonical || $canonical->state !== 'active'
                || (int) $canonical->initial_hold_id !== (int) $record->id || (int) $canonical->wallet_id !== (int) $wallet->id
                || (int) $canonical->user_id !== (int) $record->user_id || $canonical->currency !== $record->currency
                || bccomp((string) $canonical->amount, (string) $record->amount, 2) !== 0
                || ! hash_equals($record->terms_hash, $canonical->terms_hash)) {
                $this->guard->refuse();
            }
        }

        return $total;
    }

    private function owned(Withdrawal $row, Wallet $wallet): object
    {
        $this->boundary();
        $this->guard->assertTerms($row, $wallet);
        $record = DB::table(self::TABLE)->where('withdrawal_id', $row->id)->lockForUpdate()->first();
        if (! $record) {
            $this->guard->refuse();
        }
        $this->assertHeader($record, $wallet);
        if (! hash_equals($record->terms_hash, $this->guard->termsFingerprint($row))) {
            $this->guard->refuse();
        }

        return $record;
    }

    private function assertHeader(object $record, Wallet $wallet): void
    {
        try {
            $expected = ['withdrawal_id' => (int) $record->withdrawal_id, 'wallet_id' => (int) $record->wallet_id,
                'user_id' => (int) $record->user_id, 'currency' => $record->currency,
                'amount' => Money::fromDatabase((string) $record->amount, $wallet->currency)->toString(), 'terms_hash' => $record->terms_hash];
            if ((int) $record->wallet_id !== (int) $wallet->id || (int) $record->user_id !== (int) $wallet->user_id
                || $record->currency !== $wallet->currency->value || bccomp($expected['amount'], '0', 2) <= 0
                || json_decode(Crypt::decryptString($record->origin_cipher), true, 32, JSON_THROW_ON_ERROR) !== $expected) {
                $this->guard->refuse();
            }
        } catch (\Throwable) {
            $this->guard->refuse();
        }
    }

    private function boundary(): void
    {
        if (DB::transactionLevel() < 1 || ! DB::connection()->getPdo()->inTransaction()) {
            $this->guard->refuse();
        }
    }
}
