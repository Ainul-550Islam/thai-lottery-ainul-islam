<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\DTOs\BetPurchaseData;
use App\DTOs\BetPurchaseResult;
use App\DTOs\Betting\BetAmendmentData;
use App\DTOs\Betting\BetAmendmentResult;
use App\DTOs\Betting\BetCancellationData;
use App\Enums\BetAmendmentStatus;
use App\Enums\BetCancellationReason;
use App\Enums\BetStatus;
use App\Enums\Currency;
use App\Exceptions\BetAmendmentException;
use App\Exceptions\BetPurchaseException;
use App\Exceptions\BetPurchaseValidationException;
use App\Models\Bet;
use App\Models\BetAmendment;
use App\Models\BetItem;
use App\Services\Finance\WalletLockService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bet amendment = refund-and-replace, owned end to end.
 *
 * THE SHAPE OF AN AMENDMENT
 *   1. Everything that can refuse cheaply refuses BEFORE any row is written: the
 *      caller's scope, the amendment window, the draw, and the replacement proposal
 *      itself (draw open, market active, number available, stake within the risk
 *      ceiling, funds sufficient). A refusal here leaves the database untouched.
 *   2. One committed transaction creates the amendment row, performs the player's
 *      refund through the ordinary cancellation service, and writes the non-expiring
 *      ownership row that records what was refunded and what is about to be attempted.
 *   3. The replacement is then bought through the completely ordinary purchase
 *      pipeline, carrying a permit minted from that ownership row. When it commits, the
 *      same transaction that funded it flips the ownership row to applied.
 *
 * WHY THE ATTEMPT IS COMMITTED BEFORE THE REPLACEMENT IS TRIED
 * A refund that has already left the account cannot be undone by a replacement that
 * fails, and a replacement that may or may not have committed cannot be guessed at. So
 * the attempt row is durable first, in state `attempting`. From then on the amendment is
 * either applied (proved by the replacement purchase it owns) or it is stuck at
 * `attempting` and refuses every retry: an unknown outcome is an operator question, not
 * something this service re-sends. That is why a permit can only be minted for a claim
 * that is still untouched.
 *
 * WHAT A FAILED REPLACEMENT MEANS FOR THE PLAYER
 * The old bet is cancelled and the stake is back in the wallet — that half is complete
 * and is never compensated away. Where the purchase pipeline refused before touching
 * money for a named, provable reason (a validation refusal), the amendment is recorded
 * Failed with that code and can be read back forever. Where the reason is unknown, the
 * attempt stays open and nothing is claimed about it either way.
 */
class BetAmendmentService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly BetCancellationService $cancellations,
        private readonly BetAmendmentOwnership $ownership,
    ) {
    }

    /**
     * Apply an amendment: cancel+refund the original, then buy the replacement.
     */
    public function amend(BetAmendmentData $data): BetAmendmentResult
    {
        // A recorded amendment answers for itself. Replay comes first and re-validates
        // nothing: the draw may have closed, the policy may have changed, and none of
        // that can un-apply money that already moved.
        $existing = BetAmendment::query()
            ->where('idempotency_key', $this->idempotencyKeyFor($data->userId, $data->clientKey))
            ->where('user_id', $data->userId)
            ->first();

        if ($existing instanceof BetAmendment) {
            return $this->replay($existing, $data);
        }

        // A row carrying the RAW client key is not this lane's record: every attempt
        // this service creates stores an owner-namespaced key (`idempotencyKeyFor`), so
        // an unnamespaced key is either history from before that rule or a row written
        // by hand. Either way it is an attempt whose ownership was never enrolled — it
        // has no attempt row, so nothing can prove whose money moved or how much. It is
        // refused rather than ignored (which would run a second amendment alongside it)
        // and refused rather than adopted (which would claim somebody else's history).
        if (BetAmendment::query()->where('idempotency_key', $data->clientKey)->exists()) {
            throw BetAmendmentException::replacementRefused(
                0,
                'An amendment with this request key exists outside the owned amendment lane; '
                .'it cannot be replayed or superseded. Controlled review required.',
                ['bet' => $data->betIdentifier, 'request_key' => $data->clientKey],
            );
        }

        $this->assertRoot();
        $this->assertEnabled();

        if (! $data->changesSomething()) {
            throw BetAmendmentException::nothingToChange(0, ['bet' => $data->betIdentifier]);
        }

        $bet = $this->resolveBet($data);
        $this->assertAmendable($bet);

        $market = $this->marketOf($bet);
        $oldNumber = $this->firstNumberOf($bet);
        $oldStake = bcadd((string) $bet->stake_amount, '0', 2);
        $newNumber = $data->newNumber ?? $oldNumber;
        $newStake = $data->newStake !== null ? bcadd($data->newStake, '0', 2) : $oldStake;
        $currency = $this->currencyValue($bet);
        $replacementKey = $this->replacementClientKey($data->userId, $data->clientKey);
        $storedKey = $this->idempotencyKeyFor($data->userId, $data->clientKey);

        $proposal = new BetPurchaseData(
            userId: $data->userId,
            drawId: (int) $bet->draw_id,
            marketKey: $market,
            rawNumber: $newNumber,
            rawStake: $newStake,
            idempotencyKey: $replacementKey,
        );

        // ---- One committed transaction: the report row, the refund, the attempt ----
        [$amendment, $attemptId, $replay] = $this->database->transaction(function () use ($data, $bet, $proposal, $oldNumber, $oldStake, $newNumber, $newStake, $currency, $market, $replacementKey, $storedKey): array {
            $wallet = app(WalletLockService::class)->lock($this->fundingWalletId($bet));

            // A competing request may have committed while this request waited
            // on the funding wallet. Re-read both request ownership and the bet
            // under current locks before creating any amendment/refund.
            $existing = BetAmendment::query()->where('idempotency_key', $storedKey)->lockForUpdate()->first();
            if ($existing instanceof BetAmendment) {
                return [$existing, null, true];
            }
            if (BetAmendment::query()->where('bet_id', $bet->getKey())->lockForUpdate()->exists()) {
                throw BetAmendmentException::withCode('bet_amendment_request_conflict',
                    'The original bet already has a different amendment; no second refund was authorized.');
            }
            $lockedBet = Bet::withTrashed()->whereKey($bet->getKey())->lockForUpdate()->first();
            if (! $lockedBet instanceof Bet || $lockedBet->trashed() || (int) $lockedBet->user_id !== $data->userId) {
                throw BetAmendmentException::betUnavailable($data->betIdentifier);
            }
            $this->assertAmendable($lockedBet);
            $bet = $lockedBet;

            // The original claim is proved BEFORE the refund revokes it: afterwards its
            // state is 'cancelled' and it can no longer be read as the live owner.
            $original = app(BetPurchaseOwnership::class)->funded($bet, $wallet);

            $amendment = BetAmendment::query()->create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $data->userId,
                'bet_id' => (int) $bet->getKey(),
                'status' => BetAmendmentStatus::Pending,
                'currency' => $currency,
                'old_number' => $oldNumber,
                'old_stake' => $oldStake,
                'new_number' => $newNumber,
                'new_stake' => $newStake,
                'refunded_amount' => '0.00',
                'idempotency_key' => $storedKey,
                'metadata' => array_filter([
                    'market' => $market,
                    'channel' => $data->metadata['channel'] ?? null,
                    'ignored_client_fields' => $data->ignoredClientFields !== [] ? $data->ignoredClientFields : null,
                ]),
            ]);

            // Leg 1: the player's money comes back through the ordinary, fully proved
            // cancellation lane, so an amendment cannot refund what a cancellation could
            // not. That lane owns the coordinated part too — an attributed sale's agent
            // commission is cancelled inside it, before the refund — which is why the
            // amendment never touches commission rows itself.
            $cancellation = $this->cancellations->cancel(new BetCancellationData(
                userId: $data->userId,
                betIdentifier: (string) $bet->getKey(),
                reason: BetCancellationReason::Amendment,
                clientKey: $data->clientKey . ':cancel',
            ));

            $amendment->forceFill(['refunded_amount' => $cancellation->refundAmount])->save();

            // Canonical proposal validation happens HERE, after the refund released the
            // original reservation and still inside the same transaction. Two reasons:
            // the replacement must be measured against the capacity this amendment just
            // gave back (otherwise "same number, bigger stake" could never be legal), and
            // a refusal must roll the refund back with it — a player is never left
            // refunded-with-nothing because their replacement could not be accepted.
            app(BetPurchaseValidator::class)->validate($proposal);

            $insert = [
                'amendment_id' => (int) $amendment->getKey(),
                'purchase_ownership_id' => (int) $original->id,
                'wallet_id' => (int) $wallet->getKey(),
                'user_id' => $data->userId,
                'draw_id' => (int) $bet->draw_id,
                'currency' => $currency,
                'market' => $market,
                'old_number' => $oldNumber,
                'old_stake' => $oldStake,
                'new_number' => $newNumber,
                'new_stake' => $newStake,
                'source_hash' => $this->ownership->sourceHash($original),
                'request_hash' => $this->ownership->requestHash($data->userId, (int) $bet->getKey(), $newNumber, $newStake),
                'replacement_client_key' => $replacementKey,
                'replacement_bet_key' => app(BetPurchaseIdempotencyService::class)->betKeyFor($proposal),
                'replacement_financial_key' => app(BetPurchaseIdempotencyService::class)->financialKeyFor($proposal),
                'refund_transaction_id' => (int) $cancellation->refund->getKey(),
                'state' => 'attempting',
                'created_at' => now(),
                'updated_at' => now(),
            ];

            // The terms hash covers the row's own identity, so it is computed over the
            // finished values and written in the same statement that creates the row.
            $row = (object) $insert;
            $insert['terms_hash'] = $this->ownership->termsHash($row, $amendment);
            $id = DB::table('bet_amendment_ownerships')->insertGetId($insert);

            return [$amendment, (int) $id, false];
        }, 3);

        if ($replay) {
            // Replay after releasing the root claim transaction, with its normal
            // original-specific proof; an in-flight attempt may safely refuse.
            return $this->replay($amendment, $data);
        }

        // ---- The replacement attempt ------------------------------------------------
        // Minted inside a transaction because minting asserts the claim is still fresh
        // and untouched. It is not a replayable token: once the attempt row leaves
        // 'attempting' no permit can be minted for it again.
        $permit = $this->database->transaction(
            fn (): BetAmendmentPermit => BetAmendmentPermit::forFreshClaim($amendment),
            3,
        );

        try {
            // Evaluate the purchase FIRST: the amendment row is only complete once the
            // replacement transaction (which flips it to applied) has committed, so a
            // refresh placed before it would read the row as it was a moment ago.
            $purchase = app(BetAmendmentReplacement::class)->purchaseAmendment($proposal, $permit);

            return $this->appliedResult($amendment->refresh(), $purchase);
        } catch (\Throwable $failure) {
            // Did it commit after all? An exception can arrive after the purchase's own
            // transaction committed (lost acknowledgement). The durable answer is the
            // attempt row, so the outcome is read from there, never inferred from the
            // exception type.
            $recovered = $this->recoverApplied($amendment, $attemptId);

            if ($recovered instanceof BetAmendmentResult) {
                return $recovered;
            }

            // A named refusal happened before any money moved in the purchase pipeline, so
            // the amendment closes as Failed with that exact code — and the refund that
            // already committed stays exactly where it is. Both the validation lane and the
            // purchase lane carry a code; the durable row, not the exception class, decided
            // above whether anything actually committed.
            $code = match (true) {
                $failure instanceof BetPurchaseValidationException => $failure->errorCode(),
                $failure instanceof BetPurchaseException => $failure->errorCode(),
                default => null,
            };

            if (is_string($code) && $code !== '') {
                $this->closeAsFailed($amendment, $code);

                return $this->resultFromRow($amendment->refresh());
            }

            // Unknown outcome: the refund committed but the replacement's fate is not
            // decidable from any row. The attempt stays exactly as it is (so no sweep, no
            // retry and no second purchase can adopt it) and the caller is refused with a
            // code that names that state instead of a transport error.
            $this->ownership->refuse();
        }
    }

    /**
     * Backfill stored Expired on pending rows that never acquired an ownership attempt.
     *
     * A row WITH an attempt is never expired: its refund may already have committed, and
     * "expired" would be a claim about money this sweep cannot make.
     *
     * @return int rows updated
     */
    public function sweepExpired(): int
    {
        return BetAmendment::query()
            ->expiredPending()
            ->whereNotIn('id', DB::table('bet_amendment_ownerships')->select('amendment_id'))
            ->update(['status' => BetAmendmentStatus::Expired->value]);
    }

    // ----------------------------------------------------------------------
    // Replay
    // ----------------------------------------------------------------------

    /**
     * Read a recorded amendment back, proving it from its own ownership row.
     *
     * A row on its own is a label. What makes it an outcome is the non-expiring attempt
     * row: its hashes, its refund, and — when applied — the replacement purchase it owns.
     * A row without one (a legacy label, a hand-written record) is never adopted.
     */
    private function replay(BetAmendment $amendment, BetAmendmentData $data): BetAmendmentResult
    {
        return $this->database->transaction(function () use ($amendment, $data): BetAmendmentResult {
            $hint = DB::table('bet_amendment_ownerships')->where('amendment_id', (int) $amendment->getKey())->first();
            if (! $hint) {
                $this->ownership->refuse();
            }
            // Match the refund/replacement wallet-first order. The non-locking
            // hint routes only; the locked row below must prove the same owner.
            $wallet = app(WalletLockService::class)->lock((int) $hint->wallet_id);
            $attempt = DB::table('bet_amendment_ownerships')->where('amendment_id', (int) $amendment->getKey())->lockForUpdate()->first();
            if (! $attempt || (int) $attempt->wallet_id !== (int) $wallet->id) {
                $this->ownership->refuse();
            }
            $amendment = $this->ownership->rows()->whereKey((int) $amendment->getKey())->lockForUpdate()->first();
            if (! $amendment instanceof BetAmendment) {
                $this->ownership->refuse();
            }
            $bet = Bet::withTrashed()->whereKey((int) $amendment->bet_id)->lockForUpdate()->first();

            if (! $bet instanceof Bet || $bet->trashed()) {
                $this->ownership->refuse();
            }

            // The caller's payload must be the recorded one. A different stake or number
            // under the same key is a different request, not a retry.
            if ($data->userId !== (int) $attempt->user_id
                || ($data->newStake !== null && bccomp(bcadd($data->newStake, '0', 2), (string) $attempt->new_stake, 2) !== 0)
                || ($data->newNumber !== null && (string) $data->newNumber !== (string) $attempt->new_number)
                || $data->betIdentifier !== (string) $bet->getKey()) {
                throw BetAmendmentException::withCode('bet_amendment_request_conflict',
                    'This amendment key already records a different request.');
            }

            if ($attempt->state === 'applied' || $attempt->state === 'failed') {
                // Both terminal states are proved the same way: the refund, its source
                // purchase, and (for an applied row) the replacement it owns.
                $this->ownership->terminal($attempt, $amendment, $wallet);
            } elseif ($attempt->state === 'attempting') {
                // An open attempt is not an outcome. The refund may well have committed,
                // which is exactly why nothing may be inferred or retried: the caller is
                // told the amendment needs original-specific review.
                $this->ownership->check($attempt, $amendment);
                $this->ownership->original($attempt, $amendment, $wallet);
                $this->ownership->refuse();
            } else {
                $this->ownership->refuse();
            }

            return $this->resultFromRow($amendment, $bet);
        }, 3);
    }

    // ----------------------------------------------------------------------
    // Internals
    // ----------------------------------------------------------------------

    private function assertRoot(): void
    {
        if (DB::transactionLevel() !== 0 || DB::connection()->getPdo()->inTransaction()) {
            throw BetAmendmentException::withCode('bet_amendment_root_required',
                'An amendment must own its root transaction; a caller transaction could roll the refund back after it committed.');
        }
    }

    private function assertEnabled(): void
    {
        if (! (bool) config('lottery.amendment.enabled', true)) {
            throw BetAmendmentException::disabled();
        }
    }

    private function resolveBet(BetAmendmentData $data): Bet
    {
        $identifier = $data->betIdentifier;

        $query = Bet::query()->where('user_id', $data->userId);
        $query->where(static function ($q) use ($identifier): void {
            if (ctype_digit($identifier)) {
                $q->where('id', (int) $identifier);
            } else {
                $q->where('bet_number', $identifier)->orWhere('uuid', $identifier);
            }
        });

        /** @var Bet|null $bet */
        $bet = $query->first();

        if ($bet === null) {
            throw BetAmendmentException::betUnavailable($identifier);
        }

        return $bet;
    }

    private function assertAmendable(Bet $bet): void
    {
        if ($bet->status === BetStatus::Cancelled) {
            throw BetAmendmentException::notAmendable((int) $bet->getKey(), 'the bet is already cancelled');
        }

        if (! $bet->status->canCancel()) {
            throw BetAmendmentException::notAmendable((int) $bet->getKey(), 'the bet is already ' . $bet->status->value);
        }

        $window = (int) config('lottery.amendment.window_minutes', 60);
        if ($window > 0) {
            $placedAt = $bet->placed_at ?? $bet->created_at;
            if ($placedAt === null || ! $placedAt->copy()->addMinutes($window)->isFuture()) {
                throw BetAmendmentException::windowExpired((int) $bet->getKey(), $window);
            }
        }
    }

    private function marketOf(Bet $bet): string
    {
        $market = $bet->metadata['market'] ?? null;

        if (! is_string($market) || $market === '') {
            throw BetAmendmentException::notAmendable((int) $bet->getKey(), 'the original market could not be determined');
        }

        return $market;
    }

    private function firstNumberOf(Bet $bet): string
    {
        /** @var BetItem|null $item */
        $item = $bet->items()->orderBy('id')->first();

        return $item !== null ? (string) $item->number : '';
    }

    private function currencyValue(Bet $bet): string
    {
        return $bet->currency instanceof Currency ? $bet->currency->value : (string) $bet->currency;
    }

    private function fundingWalletId(Bet $bet): int
    {
        $walletId = DB::table('bet_purchase_ownerships')->where('bet_id', (int) $bet->getKey())->value('wallet_id');

        if ($walletId === null) {
            throw BetAmendmentException::notAmendable((int) $bet->getKey(), 'the original purchase is not owned');
        }

        return (int) $walletId;
    }

    /**
     * The replacement's client key.
     *
     * Derived, not concatenated: two different amendment keys must never share a
     * purchase key, and client keys are allowed to be longer than the column the
     * purchase key is stored in. A hash of the owner and the key is short, stable, and
     * recomputable on replay — which a truncated concatenation is not.
     */
    /**
     * The idempotency key actually stored for this request.
     *
     * `bet_amendments.idempotency_key` is UNIQUE across the whole table, so a raw
     * client key cannot be stored as-is: two players who happen to send the same
     * string — and clients generate them from nothing in particular — would
     * collide, and the second player's amendment would die on a unique index
     * because somebody else already used "request-1". Namespacing the raw key by
     * the authenticated owner makes the key mean what the client meant: a retry
     * of THIS player's request, not an identifier shared with the rest of the
     * estate. The hash keeps it inside the column's 128 characters however long
     * the client key is, and hashing avoids leaking a client's chosen string into
     * an index.
     */
    public function idempotencyKeyFor(int $userId, string $clientKey): string
    {
        return 'amend-v1-'.$userId.'-'.hash('sha256', $clientKey);
    }

    private function replacementClientKey(int $userId, string $clientKey): string
    {
        return 'amendment-'.substr(hash('sha256', $userId . '|' . $clientKey), 0, 54);
    }

    private function appliedResult(BetAmendment $amendment, BetPurchaseResult $purchase): BetAmendmentResult
    {
        return new BetAmendmentResult(
            amendment: $amendment,
            originalBet: Bet::withTrashed()->whereKey((int) $amendment->bet_id)->firstOrFail(),
            replacementBet: $purchase->bet,
            status: BetAmendmentStatus::Applied,
            refundedAmount: bcadd((string) $amendment->refunded_amount, '0', 2),
            chargedAmount: $amendment->charged_amount !== null ? bcadd((string) $amendment->charged_amount, '0', 2) : null,
            currency: $amendment->currency instanceof Currency ? $amendment->currency->value : (string) $amendment->currency,
            failureReason: $amendment->failure_reason,
        );
    }

    /**
     * Read the outcome from the durable rows: did the replacement commit after all?
     */
    private function recoverApplied(BetAmendment $amendment, int $attemptId): ?BetAmendmentResult
    {
        $row = DB::table('bet_amendment_ownerships')->where('id', $attemptId)->first();

        if (! $row || $row->state !== 'applied') {
            return null;
        }

        $replacement = Bet::withTrashed()->where('idempotency_key', (string) $row->replacement_bet_key)->first();

        if (! $replacement instanceof Bet) {
            return null;
        }

        $amendment->refresh();

        return new BetAmendmentResult(
            amendment: $amendment,
            originalBet: Bet::withTrashed()->whereKey((int) $amendment->bet_id)->firstOrFail(),
            replacementBet: $replacement,
            status: BetAmendmentStatus::Applied,
            refundedAmount: bcadd((string) $amendment->refunded_amount, '0', 2),
            chargedAmount: $amendment->charged_amount !== null ? bcadd((string) $amendment->charged_amount, '0', 2) : null,
            currency: $amendment->currency instanceof Currency ? $amendment->currency->value : (string) $amendment->currency,
            failureReason: null,
        );
    }

    private function closeAsFailed(BetAmendment $amendment, string $code): void
    {
        $this->database->transaction(function () use ($amendment, $code): void {
            $attempt = DB::table('bet_amendment_ownerships')->where('amendment_id', (int) $amendment->getKey())->lockForUpdate()->first();

            if (! $attempt || $attempt->state !== 'attempting') {
                return;
            }

            DB::table('bet_amendment_ownerships')->where('id', (int) $attempt->id)->update([
                'state' => 'failed',
                'failure_code' => $code,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

            $amendment->forceFill([
                'status' => BetAmendmentStatus::Failed,
                'failure_reason' => $code,
            ])->save();
        }, 3);
    }

    private function resultFromRow(BetAmendment $amendment, ?Bet $originalBet = null): BetAmendmentResult
    {
        $originalBet ??= Bet::withTrashed()->whereKey((int) $amendment->bet_id)->firstOrFail();

        return new BetAmendmentResult(
            amendment: $amendment,
            originalBet: $originalBet,
            replacementBet: $amendment->replacement_bet_id !== null
                ? Bet::withTrashed()->whereKey((int) $amendment->replacement_bet_id)->first()
                : null,
            status: $amendment->status,
            refundedAmount: bcadd((string) $amendment->refunded_amount, '0', 2),
            chargedAmount: $amendment->charged_amount !== null
                ? bcadd((string) $amendment->charged_amount, '0', 2)
                : null,
            currency: $amendment->currency instanceof Currency
                ? $amendment->currency->value
                : (string) $amendment->currency,
            failureReason: $amendment->failure_reason,
        );
    }
}
