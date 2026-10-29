<?php

declare(strict_types=1);

namespace App\Services\Wallet\Conversion;

use App\Contracts\Wallet\ConversionPolicyContract;
use App\Contracts\Wallet\WalletConversionServiceContract;
use App\DTOs\Wallet\ConversionAuditContextData;
use App\DTOs\Wallet\ConversionHistoryFilterData;
use App\DTOs\Wallet\ConversionQuoteData;
use App\DTOs\Wallet\ConversionRequestData;
use App\DTOs\Wallet\ConversionResultData;
use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\LedgerEntryType;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Enums\WalletConversionChannel;
use App\Enums\WalletConversionDirection;
use App\Enums\WalletConversionQuoteStatus;
use App\Enums\WalletConversionStatus;
use App\Enums\WalletType;
use App\Exceptions\Wallet\ConversionException;
use App\Models\FinancialTransaction;
use App\Models\Wallet;
use App\Models\WalletConversion;
use App\Models\WalletConversionQuote;
use App\Repositories\Wallet\WalletConversionAuditRepository;
use App\Services\Finance\FinancialTransactionService;
use App\Services\Finance\LedgerPostingService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletLockService;
use App\Services\Finance\WalletService;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class WalletConversionService implements WalletConversionServiceContract
{
    public function __construct(
        private readonly ConversionPolicyContract $policy,
        private readonly ConversionFeeCalculator $fees,
        private readonly ConversionQuoteService $quotes,
        private readonly WalletLockService $locks,
        private readonly WalletService $wallets,
        private readonly LedgerPostingService $ledger,
        private readonly FinancialTransactionService $transactions,
        private readonly Config $config,
        private readonly ConversionIntegrityGuard $integrity,
        private readonly WalletConversionAuditRepository $audits,
        private readonly ConversionWalletEligibility $eligibility,
        private readonly StripeRefundConversionGuard $refundGuard,
        private readonly ConversionLimitService $limits,
    ) {}

    public function quote(ConversionRequestData $request): ConversionQuoteData
    {
        return $this->quotes->quote($request);
    }

    public function convert(ConversionRequestData $request): ConversionResultData
    {
        $this->assertIdempotencyKey($request->idempotencyKey);
        $key = self::storedKey((string) $request->idempotencyKey);

        $refundGuardCodes = ['conversion_refund_exposure_pending', 'conversion_refund_exposure_unproven'];

        try {
            return $this->convertInTransaction($request, $key);
        } catch (ConversionException $refusal) {
            // A refusal by the deposit-refund custody guard is an EVENT, not just an
            // error: support will be asked why a member with money in refund recovery
            // could not convert, and "we refused it" is only provable if the refusal
            // was written down. It is written AFTER the transaction has rolled back, so
            // the record survives exactly the refusals it describes and no other.
            if (in_array((string) $refusal->errorCode(), $refundGuardCodes, true)) {
                $this->recordRefusalAudit($request, (string) $refusal->errorCode());
            }

            throw $refusal;
        }
    }

    /**
     * One new conversion, inside one transaction.
     */
    private function convertInTransaction(ConversionRequestData $request, string $key): ConversionResultData
    {
        return DB::transaction(function () use ($request, $key): ConversionResultData {
            // The owner is the shared mutex for quote issuance, new movements,
            // exact replay and other owner-serialized money admissions. Taking it
            // before the idempotency lookup avoids stale or competing history.
            DB::table('users')->where('id', $request->userId)->lockForUpdate()->first();

            $prior = WalletConversion::query()->where('user_id', $request->userId)
                ->where('channel', $request->channel->value)->where('idempotency_key', $key)
                ->lockForUpdate()->first();

            if ($prior) {
                // A replay is an observation of history, not a new admission: the lane
                // may since have closed, KYC may have expired and the wallet may now be
                // locked, and none of that changes what already happened. What must
                // still be true is that the history is real, so the stored movement is
                // proved in full before the row is handed back. An unprovable original
                // is refused rather than returned, because returning it would be an
                // assertion about money that nothing in the books supports.
                $proved = $this->integrity->assertSettled($prior);
                if (! hash_equals((string) $proved->request_fingerprint, $this->fingerprint($request, $this->requestedAmount($request)))) {
                    throw ConversionException::failure('idempotency_conflict', 'This idempotency key was already used for a different conversion.');
                }

                return $this->resultFrom($proved, true);
            }

            // CUSTODY COMES BEFORE PLEASANTRY. A member whose deposit refund is still
            // in recovery may be owed money, or may be trying to move money that is
            // already spoken for; either way a new conversion cannot be authorised
            // until that custody is settled. The check runs here — inside the
            // transaction, after the exact-replay shortcut and before a single wallet is
            // read — because an exact replay is history, not a new authorisation.
            $this->refundGuard->assertAllowed((int) $request->userId, $this->currencyEnum($request->currency));

            // The rolling windows are enforced HERE, by the object that owns them,
            // rather than inferred from the policy's verdict. The policy answers
            // "is this amount legal for this member right now" and reuses the same
            // totals, but only this call is made inside a proven transaction — and
            // it is the one that refuses an unprovable history instead of adding it
            // up. A limit refusal must therefore be raised by the limit service, so
            // it carries the exact configuration key and the exact attempted figure
            // an operator needs.
            $this->limits->assertAllowedForExecution(
                (int) $request->userId,
                $request->amount,
                $this->currencyEnum($request->currency),
            );

            $quote = $this->quotes->findValidForExecution($request);

            // The policy runs AFTER the quote is proved usable: a quote that has
            // expired or belongs to another owner is not a request worth evaluating,
            // and refusing it as "member ineligible" would send support the wrong way.
            $this->policy->assertAllowed($request);

            // The wallet pair is resolved and proved by the eligibility service, under
            // this transaction's locks and in its canonical order (the owner first, then
            // the wallets by id) — the same order every other money path takes, so two
            // conversions of one wallet cannot deadlock against each other.
            $pair = $this->eligibility->pairForExecution($request);
            $source = $pair['source'];
            $destination = $pair['destination'] ?? $this->openDestinationWallet($request);

            $locked = $this->locks->lockMany([(int) $source->getKey(), (int) $destination->getKey()]);
            $source = $locked[(int) $source->getKey()];
            $destination = $locked[(int) $destination->getKey()];

            $amountIn = bcadd((string) $quote->amount_in, '0', 2);
            $feeAmount = bcadd((string) $quote->fee_amount, '0', 2);
            $amountOut = bcadd((string) $quote->amount_out, '0', 2);

            // AVAILABLE balance, not the raw balance: money already locked (a pending
            // withdrawal, a placed bet) is still on the wallet but is not the member's
            // to convert, and spending it here would leave the lock unbacked.
            if (bccomp($source->getAvailableBalance(), $amountIn, 2) < 0) {
                throw ConversionException::failure('insufficient_balance', 'The source wallet no longer has enough available funds.');
            }

            // The two "before" snapshots are written for the same reason the "after"
            // ones are: they are what makes the movement arithmetically checkable
            // later. Without them a reader can only see where the balances ended up,
            // not that they moved by the amounts the conversion claims.
            $fromBefore = bcadd((string) $source->balance, '0', 2);
            $toBefore = bcadd((string) $destination->balance, '0', 2);

            $conversion = WalletConversion::query()->create([
                'reference' => 'WC-'.strtoupper(Str::random(24)),
                'user_id' => $request->userId,
                'source_wallet_id' => $source->getKey(),
                'destination_wallet_id' => $destination->getKey(),
                // The wallet CLASSES that moved. The schema requires them, and they
                // are read by an auditor who no longer has the wallets: "cash left and
                // win arrived" is a different fact from "commission moved to bonus",
                // and the two wallet ids alone stop meaning anything once the wallets
                // are renamed or closed.
                'from_wallet_type' => $this->walletType($source),
                'to_wallet_type' => $this->walletType($destination),
                'direction' => $request->direction,
                'status' => WalletConversionStatus::Pending,
                'currency' => $this->currencyCode($request->currency),
                'amount_in' => $amountIn,
                'fee_amount' => $feeAmount,
                'amount_out' => $amountOut,
                'from_balance_before' => $fromBefore,
                'to_balance_before' => $toBefore,
                'fee_rule_key' => $quote->fee_rule_key,
                'fee_rule_version' => $quote->fee_rule_version,
                'fee_rate' => $quote->fee_rate,
                'fee_bearer' => $quote->fee_bearer,
                'channel' => $request->channel->value,
                // The stored key is the DIGEST of the member's key, never the key
                // itself: it is the uniqueness handle that lets a retry find its own
                // history without the database holding a value the member can replay
                // somewhere else.
                'idempotency_key' => $key,
                'quote_reference' => $quote->reference,
                'request_fingerprint' => $this->fingerprint($request, $amountIn),
                'metadata' => $request->metadata,
            ]);

            $sourceBalance = $this->wallets->applyDebit($source, Money::of($amountIn, $this->currencyEnum($request->currency)), FinancialTransactionType::Transfer);
            $destinationBalance = $this->wallets->applyCredit($destination, Money::of($amountOut, $this->currencyEnum($request->currency)), FinancialTransactionType::Transfer);
            $financial = $this->createFinancialTransaction($request, $conversion, $amountIn, $feeAmount);
            $conversion->financial_transaction_id = $financial->getKey();
            $conversion->status = WalletConversionStatus::Processing;
            $conversion->save();
            $this->postEntries($financial, $conversion, $source, $destination, $amountIn, $amountOut, $feeAmount,
                [$sourceBalance->toString(), $destinationBalance->toString(), null]);
            $this->transactions->transitionTo($financial, TransactionStatus::Completed);
            $financial->processed_at = now();
            $financial->save();
            $conversion->status = WalletConversionStatus::Completed;
            $conversion->source_balance_after = $sourceBalance->toString();
            $conversion->destination_balance_after = $destinationBalance->toString();
            $conversion->completed_at = now();
            $conversion->save();

            // The movement is proved AFTER it is written, against what is actually in
            // the books rather than what we meant to put there. A failure here throws
            // and takes the whole transaction with it — including when the caller is
            // wrapping this in a transaction of its own and would otherwise commit a
            // half-proved conversion.
            $this->integrity->assertSettled($conversion);

            // The audit row is written INSIDE the movement's transaction and before the
            // quote is spent. "Money moved and nothing recorded it" is the one state a
            // reconciliation can never repair, so a failure to record must undo the
            // movement rather than be reported afterwards.
            $this->recordAudit($conversion, 'completed', $request->auditContext);

            $this->quotes->consume($quote, (int) $conversion->getKey());

            return $this->resultFrom($conversion, false);
        });
    }

    public function reverse(int $conversionId, ConversionAuditContextData $context, string $reason, ?string $idempotencyKey = null): ConversionResultData
    {
        if (! (bool) $this->config->get('conversions.reversal.enabled', true)) {
            throw ConversionException::failure('conversion_reversal_disabled', 'Conversion reversals are disabled.');
        }

        // The API channel is an operator channel too. The reverse endpoint is
        // policy-gated on the row, and every path — panel, console, HTTP — then goes
        // through ConversionReversalAuthority, which proves current database-held
        // authority. Refusing `Api` here would not add a lock on that door; it would
        // only force the HTTP controller to lie about where the action came from.
        // Members are stopped by the policy and by the authority proof, not by a
        // string comparison against the channel they happened to arrive on.
        if (! in_array($context->channel, [WalletConversionChannel::Admin, WalletConversionChannel::Console, WalletConversionChannel::Api], true)) {
            throw ConversionException::failure('conversion_reversal_forbidden', 'Only an operator may reverse a conversion.');
        }

        $minimumReason = (int) $this->config->get('conversions.reversal.min_reason_length', 10);
        if (mb_strlen(trim($reason)) < $minimumReason) {
            throw ConversionException::failure('conversion_reversal_reason_required', 'A detailed reason is required for the reversal.');
        }

        return DB::transaction(function () use ($conversionId, $context, $reason, $idempotencyKey): ConversionResultData {
            // AUTHORITY BEFORE LOCKS, AND BEFORE THE REPLAY SHORTCUT.
            //
            // Two things must be true of this check. First, it runs before the
            // conversion and wallet rows are locked: the authority proof locks the two
            // users in id order, and taking a conversion lock first would let two
            // operators reversing each other's conversions deadlock. Second, it runs
            // before the "this is a retry, hand back the existing reversal" branch,
            // because a retry is still an operator asking to see and re-affirm money
            // that moved — an actor whose rights were revoked must not be able to read
            // it back out through the retry path.
            //
            // The owner is read WITHOUT a lock purely to name whose conversion this is;
            // the authoritative row is re-read under lock immediately afterwards, and
            // nothing is decided from the unlocked copy except the identity to prove.
            //
            // Reading the row can itself refuse: a state this system does not
            // recognise is unproven accounting, and it is reported here as "there is no
            // honest inverse of this" rather than as a raw parse failure.
            try {
                $owner = WalletConversion::query()->find($conversionId);
            } catch (ConversionException) {
                throw ConversionException::failure('reversal_unavailable', 'The original conversion cannot be verified, so it cannot be reversed.');
            }
            if (! $owner) {
                throw ConversionException::failure('conversion_not_reversible', 'This conversion is not in a reversible state.');
            }

            app(ConversionReversalAuthority::class)->authorize(
                (int) $owner->user_id,
                $context->actorUserId === null ? null : (int) $context->actorUserId,
            );

            try {
                $original = WalletConversion::query()->lockForUpdate()->find($conversionId);
                // The lifecycle state is read here, inside the guard against an
                // unreadable row, so that a stored state this system does not recognise
                // is reported as "this cannot be verified" rather than as "this is not
                // reversible" — the second would suggest a rule, and there is no rule
                // that produces an unrecognisable state.
                $originalState = $original?->status;
            } catch (ConversionException) {
                throw ConversionException::failure('reversal_unavailable', 'The original conversion cannot be verified, so it cannot be reversed.');
            }
            if (! $original) {
                throw ConversionException::failure('conversion_not_reversible', 'This conversion is not in a reversible state.');
            }

            // The authority proof named an owner. Confirm, under lock, that this row is
            // still that person's. A conversion re-parented between the preload and the
            // lock would otherwise let one member's conversion be reversed on another
            // member's authority — the proof would be true of a row that no longer
            // exists in that form.
            if ((int) $original->user_id !== (int) $owner->user_id) {
                throw ConversionException::failure('reversal_forbidden', 'The conversion owner changed while the reversal was being authorised.');
            }

            // The reversal's own identity is computed BEFORE any child is looked at,
            // because it is what decides whether a child is a replay of this request or
            // somebody else's separate attempt. Computing it later would mean
            // "already reversed" could only ever be answered with a replay.
            $reversalReason = trim($reason);
            $reversalKey = self::storedKey($idempotencyKey ?? 'reverse:'.$original->getKey().':'.$reversalReason);
            $reversalFingerprint = hash('sha256', json_encode(
                ['reverse', (int) $original->getKey(), (int) ($context->actorUserId ?? 0), $reversalReason],
                JSON_THROW_ON_ERROR,
            ));

            // A pre-existing repeatable-read snapshot may predate a committed
            // reversal. Read the child as current while the original is locked.
            $child = WalletConversion::query()->where('reversal_of_id', $original->getKey())->orderBy('id')->lockForUpdate()->first();

            if ($child) {
                // A reversal already exists, so this call is a replay of it. Replaying is
                // reading history, and history is only readable if it proves: the pair
                // is handed back only when the reciprocal link and both movements hold
                // up under the proof. A broken link is reported as unproven accounting,
                // not as "already reversed".
                // ...and only when it is THIS request. A new key, a different operator
                // or a different reason is a second reversal attempt, not a retry, and
                // handing back the existing row would tell the caller their new
                // instruction had been carried out. A conversion can be reversed once.
                if (! hash_equals((string) $child->idempotency_key, $reversalKey)
                    || ! hash_equals((string) $child->request_fingerprint, $reversalFingerprint)) {
                    throw ConversionException::failure(
                        'reversal_unavailable',
                        'This conversion has already been reversed by a different request.',
                    );
                }

                $proved = $this->integrity->assertSettled($child);

                return $this->resultFrom($proved, true);
            }

            if ($originalState !== WalletConversionStatus::Completed || $original->reversal_of_id !== null) {
                throw ConversionException::failure('conversion_not_reversible', 'This conversion is not in a reversible state.');
            }

            // The original has to prove before it can be undone. Note the different
            // code: an original whose books do not add up is not "unproven accounting
            // to review", it is a conversion that cannot be offered for reversal at
            // all — there is no honest inverse of a movement nobody can verify.
            try {
                $this->integrity->assertSettled($original);
            } catch (ConversionException) {
                throw ConversionException::failure('reversal_unavailable', 'The original conversion cannot be verified, so it cannot be reversed.');
            }

            // The window is checked AFTER the proof, not before: "the window has
            // closed" is only a useful answer about a conversion whose books add up. If
            // the completion stamp itself is missing or wrong, the truthful refusal is
            // that the original cannot be verified, not that time ran out.
            $window = max(1, (int) $this->config->get('conversions.reversal.window_hours', 24));
            if (! $original->isInsideReversalWindow($window)) {
                throw ConversionException::failure('conversion_reversal_window_expired', 'The conversion is outside the reversal window.');
            }

            $locked = $this->locks->lockMany([(int) $original->source_wallet_id, (int) $original->destination_wallet_id]);
            $from = $locked[(int) $original->destination_wallet_id];
            $to = $locked[(int) $original->source_wallet_id];
            $fromAmount = bcadd((string) $original->amount_out, '0', 2);
            $toAmount = bcadd((string) $original->amount_in, '0', 2);

            // The reversal returns the FEE as well: what arrives back in the source
            // wallet is exactly what left it, so the reversal leg carries the original
            // fee with the opposite sign and the revenue account is debited back.
            $feeAmount = bcmul(bcadd((string) $original->fee_amount, '0', 2), '-1', 2);

            if (bccomp($from->getAvailableBalance(), $fromAmount, 2) < 0) {
                throw ConversionException::failure('insufficient_balance', 'The destination wallet no longer contains the amount required to reverse this conversion.');
            }

            $reverse = WalletConversion::query()->create([
                'reference' => 'WC-'.strtoupper(Str::random(24)),
                'user_id' => $original->user_id,
                'source_wallet_id' => $from->getKey(),
                'destination_wallet_id' => $to->getKey(),
                'from_wallet_type' => $this->walletType($from),
                'to_wallet_type' => $this->walletType($to),
                'direction' => $original->direction->opposite(),
                'status' => WalletConversionStatus::Processing,
                'currency' => $this->currencyCode($original->currency),
                'amount_in' => $fromAmount,
                'fee_amount' => $feeAmount,
                'amount_out' => $toAmount,
                'from_balance_before' => bcadd((string) $from->balance, '0', 2),
                'to_balance_before' => bcadd((string) $to->balance, '0', 2),
                'fee_rule_key' => $original->fee_rule_key,
                'fee_rule_version' => $original->fee_rule_version,
                'fee_rate' => $original->fee_rate,
                'fee_bearer' => $original->fee_bearer,
                'channel' => $context->channel->value,
                'idempotency_key' => $reversalKey,
                'request_fingerprint' => $reversalFingerprint,
                'reversal_of_id' => $original->getKey(),
                'reversal_reason' => $reversalReason,
                'actor_user_id' => $context->actorUserId,
                'metadata' => ['actor_user_id' => $context->actorUserId, 'request_id' => $context->requestId, 'ip_address' => $context->ipAddress],
            ]);

            $fromBalance = $this->wallets->applyDebit($from, Money::of($fromAmount, $this->currencyEnum($original->currency)), FinancialTransactionType::Transfer);
            $toBalance = $this->wallets->applyCredit($to, Money::of($toAmount, $this->currencyEnum($original->currency)), FinancialTransactionType::Transfer);
            $synthetic = new ConversionRequestData(
                direction: $original->direction->opposite(),
                amount: $fromAmount,
                currency: $this->currencyEnum($original->currency),
                channel: $context->channel,
                userId: (int) $original->user_id,
                idempotencyKey: $reversalKey,
                quoteReference: null,
                auditContext: $context,
                metadata: [],
            );
            $financial = $this->createFinancialTransaction($synthetic, $reverse, $fromAmount, '0.00', true);
            $reverse->financial_transaction_id = $financial->getKey();
            $reverse->save();
            $this->postEntries($financial, $reverse, $from, $to, $fromAmount, $toAmount, $feeAmount,
                [$fromBalance->toString(), $toBalance->toString(), null]);
            $this->transactions->transitionTo($financial, TransactionStatus::Completed);
            $financial->processed_at = now();
            $financial->save();
            $reverse->status = WalletConversionStatus::Completed;
            $reverse->source_balance_after = $fromBalance->toString();
            $reverse->destination_balance_after = $toBalance->toString();
            $reverse->completed_at = now();
            $reverse->save();

            $original->status = WalletConversionStatus::Reversed;
            $original->reversed_by_id = $reverse->getKey();
            $original->reversed_at = now();
            // The reason is written on BOTH rows. On the reversal it explains why the
            // movement exists; on the original it explains why the movement was undone,
            // which is the question an auditor asks when they open the original. The
            // two must agree — the proof compares them — so that a later edit to one
            // cannot quietly rewrite the story of the other.
            $original->reversal_reason = $reversalReason;
            $original->save();

            // The pair is proved once both rows and both movements exist: the proof
            // walks the link in both directions, so it can only run when there is a
            // link to walk.
            $this->integrity->assertSettled($reverse);

            // Two events, because two facts happened: the original was undone, and the
            // reversal completed. They get different references so that a support
            // lookup by either reference finds the half of the story it is asking about.
            $this->recordAudit($original, 'reversed', $context);
            $this->recordAudit($reverse, 'completed', $context);

            return $this->resultFrom($reverse, false);
        });
    }

    public function findForUser(string $reference, int $userId): ?WalletConversion
    {
        return WalletConversion::query()->where('reference', $reference)->where('user_id', $userId)->first();
    }

    public function history(ConversionHistoryFilterData $filter): LengthAwarePaginator
    {
        $query = WalletConversion::query()->where('user_id', $filter->userId);
        if ($filter->direction) { $query->where('direction', $filter->direction->value); }
        if ($filter->hasStatuses()) { $query->whereIn('status', $filter->statusValues()); }
        if ($filter->from) { $query->where('created_at', '>=', $filter->from); }
        if ($filter->to) { $query->where('created_at', '<=', $filter->to); }
        if ($filter->hasSearch()) { $query->where('reference', 'like', '%'.$filter->search.'%'); }
        return $query->orderByDesc('id')->paginate($filter->perPage, ['*'], 'page', $filter->page);
    }

    public function openQuotesForUser(int $userId): Collection
    {
        return WalletConversionQuote::query()->where('user_id', $userId)
            ->where('status', WalletConversionQuoteStatus::Open)->whereNull('converted_id')->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('id')->limit((int) $this->config->get('conversions.quotes.max_open_per_user', 5))->get();
    }

    /**
     * The member's conversion totals, split by what they mean.
     *
     * TWO KINDS OF NUMBER, DELIBERATELY SEPARATED
     * Counts are inventory: how many conversion rows exist and how many are settled.
     * Volumes are money, and money has a DENOMINATION — so `completed_volume_in`,
     * `completed_volume_out` and `fee_total` are reported for ONE currency, the one the
     * caller asked about, never as a sum across denominations. Adding THB to USD would
     * produce a number that looks authoritative and means nothing; `by_currency` exists
     * so a caller who genuinely wants the whole picture gets each bucket named instead.
     *
     * REVERSALS ARE NOT FRESH VOLUME
     * A reversal is a completed movement, so it counts as a row, but it is the UNDOING
     * of a conversion: counting it as new volume would double-count the member's
     * activity and count the platform's correction as trade. So volumes take rows that
     * are completed AND are not themselves reversals, and a converted original that was
     * reversed afterwards stays in the count while dropping out of the volume.
     *
     * @return array{total: int, completed: int, completed_volume_in: string, completed_volume_out: string,
     *               fee_total: string, currency: string, counts_scope: string, reversed: int,
     *               by_currency: array<string, array{completed: int, completed_volume_in: string, completed_volume_out: string, fee_total: string}>}
     */
    public function summaryForUser(int $userId, ?Currency $currency = null): array
    {
        $selected = $currency ?? Currency::primary();

        $query = WalletConversion::query()->where('user_id', $userId);
        $fresh = (clone $query)->where('status', WalletConversionStatus::Completed->value)->whereNull('reversal_of_id');

        $byCurrency = [];
        foreach (Currency::cases() as $case) {
            $scoped = (clone $fresh)->where('currency', $case->value);
            $byCurrency[$case->value] = [
                'completed' => (clone $scoped)->count(),
                'completed_volume_in' => bcadd((string) (clone $scoped)->sum('amount_in'), '0', 2),
                'completed_volume_out' => bcadd((string) (clone $scoped)->sum('amount_out'), '0', 2),
                'fee_total' => bcadd((string) (clone $scoped)->sum('fee_amount'), '0', 2),
            ];
        }

        $bucket = $byCurrency[$selected->value];

        return [
            'total' => (clone $query)->count(),
            'completed' => WalletConversion::query()->where('user_id', $userId)
                ->where('status', WalletConversionStatus::Completed->value)->count(),
            'completed_volume_in' => $bucket['completed_volume_in'],
            'completed_volume_out' => $bucket['completed_volume_out'],
            'fee_total' => $bucket['fee_total'],
            'currency' => $selected->value,
            'counts_scope' => 'all_currencies',
            'reversed' => (clone $query)->where('status', WalletConversionStatus::Reversed->value)->count(),
            'by_currency' => $byCurrency,
        ];
    }

    public function isEnabled(?WalletConversionDirection $direction = null): bool
    {
        if (! (bool) $this->config->get('conversions.enabled', false)) { return false; }
        return $direction === null || (bool) $this->config->get('conversions.directions.'.$direction->value.'.enabled', false);
    }

    private function assertIdempotencyKey(?string $key): void
    {
        if (! (bool) $this->config->get('conversions.idempotency.required', true)) { return; }
        $minimum = (int) $this->config->get('conversions.idempotency.min_key_length', 16);
        $maximum = (int) $this->config->get('conversions.idempotency.max_key_length', 128);
        if ($key === null || strlen($key) < $minimum || strlen($key) > $maximum) {
            throw ConversionException::failure('idempotency_key_required', 'A valid idempotency key is required.');
        }
    }

    /**
     * Record one auditable event for a conversion row.
     *
     * Only success paths call this. Refusals are NOT written here on purpose: a
     * refusal moves nothing, and the integrity tests this lane is held to compare the
     * complete state of the tables before and after a refused operation. Writing a row
     * on refusal would either make those comparisons meaningless or force them to
     * ignore a table that then quietly collects junk. The audit trail here is the
     * record of what happened to money, not a request log.
     */
    private function recordAudit(WalletConversion $conversion, string $event, ?ConversionAuditContextData $context): void
    {
        $this->audits->record([
            'wallet_conversion_id' => (int) $conversion->getKey(),
            'reference' => (string) $conversion->reference,
            'event' => $event,
            'channel' => $context?->channel->value
                ?? ($conversion->channel instanceof WalletConversionChannel ? $conversion->channel->value : (string) $conversion->channel),
            'actor_user_id' => $context?->actorUserId,
            'ip_address' => $context?->ipAddress,
            'user_agent' => $context?->userAgent,
            'request_id' => $context?->requestId,
            'context' => [
                'status' => $conversion->status instanceof WalletConversionStatus ? $conversion->status->value : (string) $conversion->status,
                'direction' => $conversion->direction->value,
                'currency' => $this->currencyCode($conversion->currency),
                'amount_in' => (string) $conversion->amount_in,
                'amount_out' => (string) $conversion->amount_out,
                'fee_amount' => (string) $conversion->fee_amount,
            ],
            'recorded_at' => now(),
        ]);
    }

    /**
     * Open the destination wallet, if the member has never had one.
     *
     * ONLY EXECUTION DOES THIS. A quote or an advisory check must never create a
     * wallet: quoting is a read, and a read that writes would let anybody fill a
     * member's account with empty wallets by asking for prices. At execution there is
     * money on its way into this wallet, so the row has to exist — and it is created
     * inside the movement's transaction, so a failed conversion leaves no wallet
     * behind either.
     */
    private function openDestinationWallet(ConversionRequestData $request): Wallet
    {
        Wallet::query()->firstOrCreate([
            'user_id' => $request->userId,
            'currency' => $this->currencyCode($request->currency),
            'type' => $request->toWalletType()->value,
        ]);

        $opened = Wallet::query()->where('user_id', $request->userId)
            ->where('type', $request->toWalletType()->value)
            ->where('currency', $this->currencyCode($request->currency))
            ->first();

        if (! $opened instanceof Wallet) {
            throw ConversionException::failure('wallet_locked', 'The destination wallet could not be resolved for this conversion.');
        }

        return $opened;
    }

    /**
     * Record a refusal that an investigator will need to look up later.
     *
     * Only refusals that carry a custody decision are written: the audit table is the
     * trail of what happened to money, and filling it with every mistyped amount would
     * bury the entries that matter.
     */
    private function recordRefusalAudit(ConversionRequestData $request, string $errorCode): void
    {
        $context = $request->auditContext;

        $this->audits->record([
            'wallet_conversion_id' => null,
            // There is no conversion yet — nothing moved. The reference names the
            // REQUEST (the quote that was offered, or the member and direction), so a
            // support lookup can find the refusal from what the member has in hand.
            'reference' => $request->quoteReference ?? substr('refused:'.$request->userId.':'.$request->direction->value, 0, 64),
            'event' => 'rejected',
            'error_code' => $errorCode,
            'channel' => $request->channel->value,
            'actor_user_id' => $context?->actorUserId,
            'ip_address' => $context?->ipAddress,
            'user_agent' => $context?->userAgent,
            'request_id' => $context?->requestId,
            'context' => [
                'user_id' => $request->userId,
                'direction' => $request->direction->value,
                'amount' => $request->amount,
                'currency' => $this->currencyCode($request->currency),
            ],
            'recorded_at' => now(),
        ]);
    }

    /**
     * The stored name of a wallet's class.
     */
    private function walletType(Wallet $wallet): string
    {
        $type = $wallet->type;

        return $type instanceof WalletType ? $type->value : (string) $type;
    }

    /**
     * The stored form of a member-supplied idempotency key.
     *
     * The database keeps a digest, never the key itself: the key is a handle the
     * member holds, and storing it verbatim would leave a replayable-looking value
     * in the table for anyone who reads it.
     */
    private static function storedKey(string $key): string
    {
        return hash('sha256', $key);
    }

    /**
     * The request as the amount it will actually move, normalised to two places so
     * "100", "100.0" and "100.00" are one request rather than three.
     */
    private function requestedAmount(ConversionRequestData $request): string
    {
        return bcadd((string) $request->amount, '0', 2);
    }

    /**
     * The canonical identity of a conversion request, stored on the row and
     * re-derived by the proof. It says "this exact conversion" — direction, amount,
     * currency and the quote it was priced with — and deliberately not who retried
     * it, because the same request replayed is still the same request.
     */
    private function fingerprint(ConversionRequestData $request, string $amountIn): string
    {
        return hash('sha256', json_encode(
            ['convert', $request->direction->value, bcadd($amountIn, '0', 2), $this->currencyCode($request->currency), (string) $request->quoteReference],
            JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * The three-letter code behind a currency, whether it arrives as the enum or as
     * the string a JSON payload produced.
     */
    private function currencyCode(mixed $currency): string
    {
        return $currency instanceof Currency ? $currency->value : (string) $currency;
    }

    private function currencyEnum(mixed $currency): Currency
    {
        return $currency instanceof Currency ? $currency : Currency::from((string) $currency);
    }

    private function createFinancialTransaction(ConversionRequestData $request, WalletConversion $conversion, string $amount, string $fee, bool $reversal = false): FinancialTransaction
    {
        $transaction = new FinancialTransaction;
        $transaction->fill([
            'reference_number' => $this->transactions->generateReferenceNumber(),
            'user_id' => $request->userId,
            'wallet_id' => $conversion->source_wallet_id,
            'type' => $reversal ? TransactionType::Reversal : TransactionType::Transfer,
            'currency' => $this->currencyCode($request->currency),
            'amount' => $amount,
            'fee' => $fee,
            'description' => $reversal ? 'Cash/Win conversion reversal' : 'Cash/Win conversion',
            'metadata' => ['engine_type' => 'transfer', 'conversion_reference' => $conversion->reference, 'direction' => $conversion->direction->value],
            'idempotency_key' => 'conversion:'.$conversion->user_id.':'.$conversion->idempotency_key,
            'reference_type' => 'wallet_conversion',
            'reference_id' => $conversion->getKey(),
        ]);
        $transaction->status = TransactionStatus::Pending;
        $transaction->save();
        $this->transactions->transitionTo($transaction, TransactionStatus::Processing)->save();
        return $transaction;
    }

    /**
     * Post the ledger legs for one conversion movement.
     *
     * The fee leg takes the SIGN of the fee it is given: a charge credits revenue, a
     * refund debits it. Both directions therefore post with absolute amounts and an
     * explicit entry type, which is what the ledger validator needs in order to
     * balance, and what an auditor needs in order to read a reversal as a reversal
     * rather than as a suspiciously similar conversion.
     *
     * @param  list<?string>  $balancesAfter
     */
    private function postEntries(FinancialTransaction $financial, WalletConversion $conversion, Wallet $source, Wallet $destination, string $amountIn, string $amountOut, string $signedFee, array $balancesAfter = []): void
    {
        $currency = $this->currencyEnum($conversion->currency);
        $liability = $this->ledger->resolveAccount(LedgerPostingService::accountCodeFor(WalletService::ACCOUNT_PLAYER_LIABILITY, $currency));
        $revenue = $this->ledger->resolveAccount(LedgerPostingService::accountCodeFor(WalletService::ACCOUNT_FEE_REVENUE, $currency));
        $entries = [
            ['ledger_account_id' => (int) $liability->getKey(), 'type' => LedgerEntryType::Debit, 'amount' => $amountIn, 'wallet_id' => (int) $source->getKey(), 'reference_type' => 'wallet_conversion', 'reference_id' => (int) $conversion->getKey()],
            ['ledger_account_id' => (int) $liability->getKey(), 'type' => LedgerEntryType::Credit, 'amount' => $amountOut, 'wallet_id' => (int) $destination->getKey(), 'reference_type' => 'wallet_conversion', 'reference_id' => (int) $conversion->getKey()],
        ];
        if (bccomp($signedFee, '0', 2) !== 0) {
            $entries[] = [
                'ledger_account_id' => (int) $revenue->getKey(),
                'type' => bccomp($signedFee, '0', 2) > 0 ? LedgerEntryType::Credit : LedgerEntryType::Debit,
                'amount' => ltrim($signedFee, '-'),
                // A fee is not in anybody's wallet, so the leg carries no wallet and no
                // wallet snapshot. Passing the null explicitly keeps it distinct from
                // "the caller forgot", which the ledger service fills in for us.
                'wallet_id' => null,
                'reference_type' => 'wallet_conversion',
                'reference_id' => (int) $conversion->getKey(),
            ];
        }
        $this->ledger->post($financial, $entries, $balancesAfter);
    }

    private function resultFrom(WalletConversion $conversion, bool $replayed): ConversionResultData
    {
        return new ConversionResultData(
            conversionId: (int) $conversion->getKey(),
            reference: (string) $conversion->reference,
            status: $conversion->status,
            direction: $conversion->direction,
            amountIn: (string) $conversion->amount_in,
            feeAmount: (string) $conversion->fee_amount,
            amountOut: (string) $conversion->amount_out,
            currency: $this->currencyEnum($conversion->currency),
            fromWalletId: (int) $conversion->source_wallet_id,
            toWalletId: (int) $conversion->destination_wallet_id,
            fromBalanceAfter: (string) ($conversion->source_balance_after ?? Wallet::query()->find($conversion->source_wallet_id)?->balance ?? '0.00'),
            toBalanceAfter: (string) ($conversion->destination_balance_after ?? Wallet::query()->find($conversion->destination_wallet_id)?->balance ?? '0.00'),
            financialTransactionId: $conversion->financial_transaction_id ? (int) $conversion->financial_transaction_id : null,
            idempotencyKey: $conversion->idempotency_key,
            replayed: $replayed,
            completedAt: $conversion->completed_at?->toDateTimeImmutable(),
            note: $conversion->reason,
        );
    }
}
