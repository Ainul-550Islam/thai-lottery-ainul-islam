<?php

declare(strict_types=1);

namespace App\Services\Wallet\Conversion;

use App\Contracts\Wallet\ConversionPolicyContract;
use App\DTOs\Wallet\ConversionQuoteData;
use App\DTOs\Wallet\ConversionRequestData;
use App\Enums\ConversionFeeBearer;
use App\Enums\Currency;
use App\Enums\WalletConversionDirection;
use App\Enums\WalletConversionQuoteStatus;
use App\Enums\WalletConversionRejectionReason;
use App\Exceptions\Wallet\ConversionException;
use App\Models\Wallet;
use App\Models\WalletConversionQuote;
use App\Repositories\Wallet\WalletConversionQuoteRepository;
use App\ValueObjects\ConversionLimits;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Carbon as Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ConversionQuoteService
{
    public function __construct(
        private readonly ConversionPolicyContract $policy,
        private readonly ConversionFeeCalculator $fees,
        private readonly Config $config,
        private readonly WalletConversionQuoteRepository $repository,
    ) {}

    public function quote(ConversionRequestData $request): ConversionQuoteData
    {
        $this->policy->assertAllowed($request);
        $amount = bcadd($request->amount, '0', 2);
        $fee = $this->fees->breakdownFor($request->direction, $amount, $request->currency->value);
        if (! $fee->isEnforceable()) {
            throw ConversionException::failure('feature_disabled', 'The published conversion fee rule is unavailable, so the conversion lane is disabled.');
        }
        if (bccomp($fee->netAmount, '0', 2) <= 0) {
            throw ConversionException::failure('conversion_amount_too_small_after_fee', 'The amount must be greater than the fee.');
        }
        // Price only what the member can actually spend. A quote backed by locked money
        // is a promise the execution cannot keep, and the member would find out at the
        // worst moment — after choosing an amount and confirming it.
        $this->assertAvailableBalance($request, $amount);

        // Second precision, deliberately. The cutoffs that decide whether a quote is
        // still open are stored to the second, so the issued and expiry stamps are
        // truncated here rather than being written with microseconds the comparisons
        // do not see. Otherwise a quote issued at .900 and a cutoff at .000 disagree
        // about whether the quote has expired by nine tenths of a second.
        $now = CarbonImmutable::now()->startOfSecond();
        $expires = $now->addSeconds(max(1, (int) $this->config->get('conversions.quotes.ttl_seconds', 120)));
        $reference = 'WQ-'.strtoupper(Str::random(24));
        $limits = ConversionLimits::fromConfig((array) $this->config->get('conversions.limits', []), $request->currency);

        return DB::transaction(function () use ($request, $amount, $fee, $now, $expires, $reference, $limits): ConversionQuoteData {
            // How many prices may one member be holding at once? A quote is not money,
            // but it is an offer, and an unbounded number of offers is both a way to
            // poll the tariff and a way to fill a table. The ceiling is enforced here,
            // inside the transaction that writes the quote, so two concurrent requests
            // cannot both see one free slot.
            $this->repository->assertOpenSlotAvailableForExecution(
                (int) $request->userId,
                max(1, (int) $this->config->get('conversions.quotes.max_open_per_user', 5)),
                Clock::instance($now->toDateTime()),
            );

            WalletConversionQuote::query()->create([
                'reference' => $reference,
                'user_id' => $request->userId,
                'direction' => $request->direction,
                'status' => WalletConversionQuoteStatus::Open,
                'currency' => $request->currency,
                'amount_in' => $amount,
                'fee_amount' => $fee->feeAmount,
                'amount_out' => $fee->netAmount,
                'fee_rule_key' => $fee->ruleKey,
                'fee_rule_version' => $fee->ruleVersion,
                // The rate this quote was priced at, written next to the tariff that
                // produced it. Without it the row can state the fee but not the
                // conversion rate the member was shown.
                'rate' => $fee->rate,
                'fee_rate' => $fee->rate,
                'fee_bearer' => $fee->bearer,
                'channel' => $request->channel->value,
                'request_fingerprint' => hash('sha256', implode('|', [$request->userId, $request->direction->value, $amount, $request->currency->value])),
                'limits' => $limits->toArray(),
                'issued_at' => $now,
                'expires_at' => $expires,
            ]);
            return new ConversionQuoteData(
                reference: $reference,
                direction: $request->direction,
                amountIn: $amount,
                feeAmount: $fee->feeAmount,
                amountOut: $fee->netAmount,
                rate: $fee->rate,
                currency: $request->currency,
                feeBearer: $fee->bearer,
                feeRuleKey: $fee->ruleKey,
                feeRuleVersion: $fee->ruleVersion,
                limits: $limits,
                issuedAt: $now->toDateTimeImmutable(),
                expiresAt: $expires->toDateTimeImmutable(),
                description: $fee->describe(),
            );
        });
    }

    /**
     * Refuse a decisive quote operation outside a real transaction.
     *
     * There are two notions of "in a transaction" and a quote read needs both: the
     * framework's own counter, and the connection actually holding an open
     * transaction. They can disagree — a rolled-back savepoint leaves the counter
     * ahead, a raw PDO BEGIN leaves it behind — and a quote read that is locked but
     * not actually transactional is a lock that evaporates at commit time. So the
     * check is the conjunction, and it runs before anything is read: refusing late
     * would already have taken a lock and made the caller believe the quote was
     * theirs to spend.
     */
    private function assertDecisiveTransaction(): void
    {
        if (DB::transactionLevel() < 1 || ! DB::connection()->getPdo()->inTransaction()) {
            throw ConversionException::failure('transaction_required', 'Decisive quote operation requires an active SQL transaction.');
        }
    }

    /**
     * Is a quote mandatory for this request?
     *
     * Two switches, deliberately different: `quotes.require_for_execution` closes the
     * lane globally, while `directions.<d>.requires_quote` closes it for one direction
     * (a direction whose price moves quickly). Either one means "no quote, no
     * execution"; both off means an execution may proceed unquoted.
     */
    private function quoteIsMandatory(ConversionRequestData $request): bool
    {
        if ((bool) $this->config->get('conversions.quotes.require_for_execution', true)) {
            return true;
        }

        return (bool) $this->config->get('conversions.directions.'.$request->direction->value.'.requires_quote', false);
    }

    /**
     * Assert that this request may execute against its quote, and return the quote
     * when there is one.
     *
     * This is the admission decision the executor makes before it touches money, and
     * it is separate from `findValidForExecution` because the two questions differ:
     * this one answers "is the lane open to an unquoted execution at all?", which the
     * lookup cannot answer on its own.
     */
    public function assertUsable(ConversionRequestData $request): ?WalletConversionQuote
    {
        $this->assertDecisiveTransaction();

        if (! $request->hasQuote()) {
            if ($this->quoteIsMandatory($request)) {
                throw ConversionException::failure(
                    WalletConversionRejectionReason::QuoteNotFound->value,
                    'A conversion quote is required before this conversion can execute.',
                );
            }

            return null;
        }

        return $this->findValidForExecution($request);
    }

    public function findValidForExecution(ConversionRequestData $request): WalletConversionQuote
    {
        $this->assertDecisiveTransaction();

        if (! $request->hasQuote()) {
            throw ConversionException::failure(
                WalletConversionRejectionReason::QuoteNotFound->value,
                'A conversion quote is required before this conversion can execute.',
            );
        }

        // Scoped by owner in the QUERY, not after the fact: a reference belonging to
        // another member must be indistinguishable from one that does not exist, and
        // filtering afterwards would have already locked a row this caller has no
        // business touching — while publishing, by its failure mode, that the
        // reference is real.
        $quote = WalletConversionQuote::query()
            ->where('reference', $request->quoteReference)
            ->where('user_id', $request->userId)
            ->lockForUpdate()
            ->first();

        if (! $quote) {
            throw ConversionException::failure(
                WalletConversionRejectionReason::QuoteNotFound->value,
                'That conversion quote could not be found for this member.',
            );
        }

        // Compare like with like.
        //
        // `direction` is cast to an enum on the model and is compared as an enum to
        // the request's enum. `currency` is compared by its stored value, and the
        // amount is compared with bccomp at the money scale, never with ==. Comparing
        // a raw string column to a Currency enum is always true-unequal, which made
        // every execution refuse with a mismatch it blamed on the member.
        if (! $this->sameDirection($quote->direction, $request->direction)
            || ! $this->sameCurrency($quote->currency, $request->currency)
            || bccomp((string) $quote->amount_in, $request->amount, 2) !== 0) {
            throw ConversionException::failure('conversion_quote_mismatch', 'The quote does not match the requested conversion.');
        }

        $this->assertStillCurrent($quote);

        return $quote;
    }

    /**
     * Refuse a quote that is closed, expired, or no longer priced like the tariff.
     *
     * The last part is the one that is easy to leave out and expensive to omit: a
     * quote is a PRICE, and a price that changed after the quote was issued is no
     * longer a price — executing it would charge the member a fee the platform is no
     * longer offering (or worse, one it now offers lower). Repricing inside the
     * execution transaction, against the same calculator the quote used, is what makes
     * the offer binding for exactly as long as it says it is.
     */
    private function assertStillCurrent(WalletConversionQuote $quote): void
    {
        $status = $quote->status instanceof WalletConversionQuoteStatus
            ? $quote->status
            : WalletConversionQuoteStatus::tryFrom((string) $quote->status);

        $expired = $quote->expires_at === null || ! $quote->expires_at->isFuture();

        if ($status !== WalletConversionQuoteStatus::Open || $quote->converted_id !== null || $quote->consumed_at !== null || $expired) {
            throw ConversionException::failure(
                WalletConversionRejectionReason::QuoteExpired->value,
                'This conversion quote is no longer open; request a new quote.',
            );
        }

        $currency = $quote->currency instanceof Currency ? $quote->currency->value : (string) $quote->currency;
        $price = $this->fees->breakdownFor(
            $quote->direction instanceof WalletConversionDirection ? $quote->direction : WalletConversionDirection::from((string) $quote->direction),
            bcadd((string) $quote->amount_in, '0', 2),
            $currency,
        );

        if (bccomp($price->feeAmount, (string) $quote->fee_amount, 2) !== 0
            || bccomp($price->netAmount, (string) $quote->amount_out, 2) !== 0
            || ($price->ruleKey !== null && $quote->fee_rule_key !== null && $price->ruleKey !== $quote->fee_rule_key)) {
            throw ConversionException::failure(
                WalletConversionRejectionReason::QuoteExpired->value,
                'The conversion price changed after this quote was issued; request a new quote.',
            );
        }
    }

    /**
     * Whether a stored direction and a requested one mean the same thing.
     */
    private function sameDirection(mixed $stored, mixed $requested): bool
    {
        $value = $stored instanceof WalletConversionDirection ? $stored->value : (string) $stored;
        $wanted = $requested instanceof WalletConversionDirection ? $requested->value : (string) $requested;

        return $value === $wanted;
    }

    /**
     * Whether a stored currency column and a requested Currency mean the same thing.
     */
    private function sameCurrency(mixed $stored, mixed $requested): bool
    {
        $value = $stored instanceof Currency ? $stored->value : (string) $stored;
        $wanted = $requested instanceof Currency ? $requested->value : (string) $requested;

        return strtoupper($value) === strtoupper($wanted);
    }

    /**
     * The source wallet must be able to fund the conversion right now.
     *
     * Restricted to the configured currency: this is an eligibility fact about the
     * member's own wallet, not an exchange rate, and quoting across currencies is
     * somebody else's problem.
     */
    private function assertAvailableBalance(ConversionRequestData $request, string $amount): void
    {
        $currency = $request->currency instanceof Currency ? $request->currency->value : (string) $request->currency;

        $wallet = Wallet::query()
            ->where('user_id', $request->userId)
            ->where('type', $request->fromWalletType()->value)
            ->where('currency', $currency)
            ->first();

        if (! $wallet || bccomp($wallet->getAvailableBalance(), $amount, 2) < 0) {
            throw ConversionException::failure(
                WalletConversionRejectionReason::InsufficientBalance->value,
                'The source wallet does not have enough available funds for this conversion.',
            );
        }
    }

    public function consume(WalletConversionQuote $quote, int $conversionId): void
    {
        $quote->status = WalletConversionQuoteStatus::Consumed;
        $quote->consumed_at = now();
        // The column is `converted_id`: it names the conversion this quote was
        // spent on, and it is UNIQUE on the table so one quote can never be spent
        // twice.
        $quote->converted_id = $conversionId;
        $quote->save();
    }
}
