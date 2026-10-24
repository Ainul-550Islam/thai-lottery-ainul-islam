<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\Enums\AuditAction;
use App\Enums\BetAmendmentStatus;
use App\Enums\BetStatus;
use App\Enums\RiskLevel;
use App\Exceptions\BetAmendmentException;
use App\Models\AuditLog;
use App\Models\Bet;
use App\Models\BetAmendment;
use App\Models\FinancialTransaction;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

/** Non-expiring original refund/replacement ownership. No history adoption or resend API. */
final class BetAmendmentOwnership
{
    public function refuse(): never
    {
        throw BetAmendmentException::withCode('bet_amendment_outcome_unresolved',
            'The amendment requires original-specific review; no replacement was retried or historical outcome inferred.');
    }

    public function rows()
    {
        return BetAmendment::query()->select('bet_amendments.*')->selectRaw(
            'CAST(old_stake AS CHAR) AS exact_old, CAST(new_stake AS CHAR) AS exact_new, CAST(refunded_amount AS CHAR) AS exact_refund, CAST(charged_amount AS CHAR) AS exact_charge');
    }

    /**
     * Money columns are compared as the database stores them, not through the
     * model, because a model that has been refreshed (or reloaded from a
     * relationship) loses the CAST aliases `rows()` adds and would silently
     * compare nulls. Callers hold a lock before arriving here, so reading the
     * row again is cheap and cannot race.
     */
    private function exact(object $o): object
    {
        $row = DB::table('bet_amendments')->where('id', (int) $o->amendment_id)->first();

        return $row ?? $this->refuse();
    }

    public function sourceHash(object $p): string
    {
        return hash('sha256', json_encode([$p->id, $p->bet_id, $p->wallet_id, $p->number_limit_id,
            $p->original_transaction_id, $p->currency, $p->stake_amount, $p->potential_payout,
            $p->terms_hash, $p->original_hash, $p->commission_snapshot_hash, $p->commission_snapshot_count], JSON_THROW_ON_ERROR));
    }

    public function requestHash(int $user, int $bet, string $number, string $stake): string
    {
        return hash('sha256', json_encode([$user, $bet, $number, $stake], JSON_THROW_ON_ERROR));
    }

    public function termsHash(object $o, BetAmendment $a): string
    {
        return hash('sha256', json_encode([$a->id, $a->uuid, $a->idempotency_key, $a->bet_id,
            $o->purchase_ownership_id, $o->wallet_id, $o->user_id, $o->draw_id, $o->currency, $o->market,
            $o->old_number, $o->old_stake, $o->new_number, $o->new_stake, $o->source_hash, $o->request_hash,
            $o->replacement_client_key, $o->replacement_bet_key, $o->replacement_financial_key, $o->refund_transaction_id], JSON_THROW_ON_ERROR));
    }

    public function check(object $o, BetAmendment $a): void
    {
        $row = $this->exact($o);
        if (DB::transactionLevel() < 1 || (int) $row->id !== (int) $a->id || (int) $o->user_id !== (int) $row->user_id
            || $row->currency !== $o->currency || $row->old_number !== $o->old_number || $row->new_number !== $o->new_number
            || bccomp((string) $row->old_stake, $o->old_stake, 2) !== 0 || bccomp((string) $row->new_stake, $o->new_stake, 2) !== 0
            || bccomp((string) $row->refunded_amount, $o->old_stake, 2) !== 0 || $row->expires_at !== null
            || ! hash_equals($o->terms_hash, $this->termsHash($o, $a))
            || ! hash_equals($o->request_hash, $this->requestHash((int) $o->user_id, (int) $row->bet_id, $o->new_number, $o->new_stake))) {
            $this->refuse();
        }
    }

    /** Pure funded member-refund proof. The claim can only be born with that complete root cancellation. */
    public function original(object $o, BetAmendment $a, Wallet $wallet): Bet
    {
        $bet = Bet::withTrashed()->whereKey($a->bet_id)->lockForUpdate()->first();
        if (! $bet || $bet->trashed() || (int) $bet->user_id !== (int) $o->user_id || (int) $bet->draw_id !== (int) $o->draw_id
            || $bet->status !== BetStatus::Cancelled || $bet->cancelled_reason !== 'amendment') {
            $this->refuse();
        }
        $p = app(BetPurchaseOwnership::class)->locked($bet, $wallet);
        if ((int) $p->id !== (int) $o->purchase_ownership_id || $p->state !== 'cancelled'
            || (int) $p->refund_transaction_id !== (int) $o->refund_transaction_id
            || $p->cancelled_reason !== 'amendment' || $bet->cancelled_at?->toDateTimeString() !== $p->cancelled_at
            || ! hash_equals($o->source_hash, $this->sourceHash($p))) {
            $this->refuse();
        }
        [$refund, $hash] = app(BetAccountingProof::class)->transaction((int) $o->refund_transaction_id, $bet, $wallet, true);
        if (! hash_equals($p->refund_hash, $hash) || $refund->idempotency_key !== app(BetCancellationService::class)->refundKeyFor((int) $bet->id)
            || ($refund->metadata['purchase_ownership_id'] ?? null) !== (int) $p->id
            || ($refund->metadata['original_transaction_id'] ?? null) !== (int) $p->original_transaction_id) {
            $this->refuse();
        }

        return $bet;
    }

    private function replacementHash(object $o, object $p): string
    {
        return hash('sha256', json_encode([$o->terms_hash, $p->id, $p->bet_id, $p->wallet_id,
            $p->original_transaction_id, $p->currency, $p->stake_amount, $p->potential_payout,
            $p->terms_hash, $p->original_hash, $p->commission_snapshot_hash, $p->commission_snapshot_count], JSON_THROW_ON_ERROR));
    }

    /** Called only by the NEW, fully funded purchase writer, before its commit. */
    public function captureReplacement(Bet $bet, Wallet $wallet, FinancialTransaction $fresh, ?BetAmendmentPermit $permit): void
    {
        $o = DB::table('bet_amendment_ownerships')->where('replacement_bet_key', $bet->idempotency_key)->lockForUpdate()->first();
        if (! $o) {
            return;
        } // Ordinary purchases do not acquire amendment ownership.
        $a = $this->rows()->whereKey($o->amendment_id)->lockForUpdate()->first();
        if (! $permit || ! $permit->authorizes($o) || ! $a || ! $bet->wasRecentlyCreated || ! $fresh->wasRecentlyCreated || $o->state !== 'attempting'
            || $a->status !== BetAmendmentStatus::Pending || $o->replacement_ownership_id !== null || $o->replacement_hash !== null
            || $o->failure_code !== null || $o->completed_at !== null || $a->failure_reason !== null || $a->applied_at !== null || $a->replacement_bet_id !== null || $a->charged_amount !== null
            || (int) $wallet->id !== (int) $o->wallet_id || (int) $bet->user_id !== (int) $o->user_id
            || (int) $bet->draw_id !== (int) $o->draw_id || $bet->currency->value !== $o->currency
            || ($bet->metadata['market'] ?? null) !== $o->market || $fresh->idempotency_key !== $o->replacement_financial_key) {
            $this->refuse();
        }
        $this->check($o, $a);
        $this->original($o, $a, $wallet);
        $p = app(BetPurchaseOwnership::class)->locked($bet, $wallet);
        [, $stake, , $item] = app(BetPurchaseOwnership::class)->terms($bet);
        if ($p->state !== 'active' || $stake !== $o->new_stake || $item->number !== $o->new_number
            || (int) $p->original_transaction_id !== (int) $fresh->id) {
            $this->refuse();
        }
        $stamp = now()->toDateTimeString();
        DB::table('bet_amendment_ownerships')->where('id', $o->id)->update(['state' => 'applied',
            'replacement_ownership_id' => $p->id, 'replacement_hash' => $this->replacementHash($o, $p), 'completed_at' => $stamp, 'updated_at' => now()]);
        $a->forceFill(['status' => BetAmendmentStatus::Applied, 'replacement_bet_id' => $bet->id,
            'charged_amount' => $o->new_stake, 'applied_at' => $stamp])->save();
        AuditLog::create(['user_id' => $o->user_id, 'action' => AuditAction::Update, 'risk_level' => RiskLevel::Low,
            'auditable_type' => BetAmendment::class, 'auditable_id' => $a->id,
            'description' => 'Owned amendment replacement committed with its original debit and risk ownership.',
            'metadata' => ['purchase_ownership_id' => (int) $p->id, 'original_refund_transaction_id' => (int) $o->refund_transaction_id]]);
    }

    public function assertNoReplacement(object $o): void
    {
        if (Bet::withTrashed()->where('idempotency_key', $o->replacement_bet_key)->lockForUpdate()->exists()
            || FinancialTransaction::withTrashed()->where('idempotency_key', $o->replacement_financial_key)->lockForUpdate()->exists()) {
            $this->refuse();
        }
    }

    /** A later winning/losing/cancelled replacement does not erase its original funded purchase fact. */
    public function terminal(object $o, BetAmendment $a, Wallet $wallet): ?Bet
    {
        $this->check($o, $a);
        $this->original($o, $a, $wallet);
        $row = $this->exact($o);
        if ($o->state === 'failed') {
            if ($a->status !== BetAmendmentStatus::Failed || ! $o->completed_at || ! $o->failure_code
                || $a->failure_reason !== $o->failure_code || $a->replacement_bet_id !== null || $row->charged_amount !== null
                || $a->applied_at !== null || $o->replacement_ownership_id !== null || $o->replacement_hash !== null) {
                $this->refuse();
            }
            $this->assertNoReplacement($o);

            return null;
        }
        if ($o->state !== 'applied' || $a->status !== BetAmendmentStatus::Applied || ! $o->completed_at
            || $a->applied_at?->toDateTimeString() !== $o->completed_at || $a->failure_reason !== null || $o->failure_code !== null
            || ! $o->replacement_hash || $row->charged_amount === null || bccomp((string) $row->charged_amount, $o->new_stake, 2) !== 0) {
            $this->refuse();
        }
        $p = DB::table('bet_purchase_ownerships')->where('id', $o->replacement_ownership_id)->lockForUpdate()->first();
        $bet = $p ? Bet::withTrashed()->whereKey($p->bet_id)->lockForUpdate()->first() : null;
        if (! $bet || $bet->trashed() || (int) $a->replacement_bet_id !== (int) $bet->id
            || (int) $bet->user_id !== (int) $o->user_id || (int) $bet->draw_id !== (int) $o->draw_id
            || $bet->idempotency_key !== $o->replacement_bet_key || ($bet->metadata['market'] ?? null) !== $o->market) {
            $this->refuse();
        }
        $proved = app(BetPurchaseOwnership::class)->funded($bet, $wallet);
        [, $stake, , $item] = app(BetPurchaseOwnership::class)->terms($bet, false);
        $tx = FinancialTransaction::withTrashed()->whereKey($proved->original_transaction_id)->lockForUpdate()->first();
        if ($stake !== $o->new_stake || $item->number !== $o->new_number || ! $tx || $tx->idempotency_key !== $o->replacement_financial_key
            || ! hash_equals($o->replacement_hash, $this->replacementHash($o, $proved))) {
            $this->refuse();
        }

        return $bet;
    }
}
