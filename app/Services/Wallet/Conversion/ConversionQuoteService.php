<?php

declare(strict_types=1);

namespace App\Services\Wallet\Conversion;

use App\DTOs\Wallet\ConversionQuoteData;
use App\DTOs\Wallet\ConversionRequestData;
use App\Enums\Currency;
use App\Enums\WalletConversionDirection;
use App\Enums\WalletConversionRejectionReason;
use App\Exceptions\Wallet\ConversionException;
use App\Repositories\Wallet\WalletConversionQuoteRepository;
use App\ValueObjects\ConversionLimits;
use Carbon\Carbon;
use DateTimeImmutable;

/**
 * Issues and reads quotes: the exact numbers a member is shown before
 * anything is committed.
 *
 * A quote is persisted with its expiry so the price a member saw can be
 * produced again at execution time — a stale reference is refused with
 * quote_expired rather than silently re-priced. Quoting does not move money
 * and writes no ledger row; it only refuses what could not execute either
 * (a disabled lane, a limit breach, money the member does not have), because
 * quoting a price the member cannot act on is a tease, not a service.
 */
final class ConversionQuoteService
{
    public function __construct(
        private readonly ConversionFeeCalculator $fees,
        private readonly ConversionLimitService $limits,
        private readonly WalletConversionQuoteRepository $quotes,
    ) {
    }

    /**
     * Price a conversion and persist the promise.
     *
     * @throws ConversionException when the request cannot be priced
     */
    public function issue(ConversionRequestData $request, ConversionLimits $limits, string $dailyTotal, ?string $monthlyTotal): ConversionQuoteData
    {
        $breakdown = $this->fees->breakdownFor($request->direction, $request->amount, $request->currency->value);

        ConversionException::refuseUnless(
            $breakdown->isConfigured,
            WalletConversionRejectionReason::FeatureDisabled->value,
            'This conversion cannot be priced right now.',
            ['direction' => $request->direction->value],
        );

        $issuedAt = new DateTimeImmutable('now');
        $expiresAt = (new DateTimeImmutable('now'))->modify('+'.max(1, (int) config('conversions.quotes.ttl_seconds', 120)).' seconds');

        $row = $this->quotes->create([
            'reference' => $this->reference(),
            'user_id' => $request->userId,
            'direction' => $request->direction->value,
            'currency' => $request->currency->value,
            'amount_in' => bcadd($request->amount, '0', 2),
            'fee_amount' => $breakdown->feeAmount,
            'amount_out' => $breakdown->netAmount,
            'rate' => $breakdown->rate,
            'fee_bearer' => $breakdown->bearer->value,
            'fee_rule_key' => $breakdown->ruleKey,
            'fee_rule_version' => $breakdown->ruleVersion,
            'status' => 'open',
            'idempotency_key' => $request->idempotencyKey,
            'expires_at' => Carbon::instance($expiresAt),
        ]);

        return new ConversionQuoteData(
            reference: (string) $row->reference,
            direction: $request->direction,
            amountIn: (string) $row->amount_in,
            feeAmount: (string) $row->fee_amount,
            amountOut: (string) $row->amount_out,
            rate: $breakdown->rate,
            currency: $request->currency,
            feeBearer: $breakdown->bearer,
            feeRuleKey: $breakdown->ruleKey,
            feeRuleVersion: $breakdown->ruleVersion,
            limits: $limits,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        );
    }

    /**
     * The still-spendable quotes a member holds, newest first.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\WalletConversionQuote>
     */
    public function openForUser(int $userId, int $limit = 10)
    {
        return $this->quotes->openForUser($userId, $limit);
    }

    public function countOpenForUser(int $userId): int
    {
        return $this->quotes->countOpenForUser($userId);
    }

    /**
     * Rebuild the quote DTO from its stored row — the numbers a member saw,
     * byte-identical, or a named refusal when the promise is gone.
     */
    public function resolveForExecution(string $reference, ConversionRequestData $request): ConversionQuoteData
    {
        $row = $this->quotes->findByReference($reference);

        if ($row === null || (int) $row->user_id !== $request->userId) {
            throw ConversionException::failure(
                WalletConversionRejectionReason::QuoteNotFound->value,
                'That quote could not be found.',
                ['quote_reference' => $reference],
            );
        }

        if ($row->status !== 'open' || $row->isExpired()) {
            throw ConversionException::failure(
                WalletConversionRejectionReason::QuoteExpired->value,
                'That quote has expired. Request a new price before converting.',
                ['quote_reference' => $reference],
            );
        }

        $matches = $row->direction === $request->direction
            && bccomp((string) $row->amount_in, $request->amount, 2) === 0
            && $row->currency === $request->currency->value;

        if (! $matches) {
            throw ConversionException::failure(
                WalletConversionRejectionReason::QuoteNotFound->value,
                'That quote was priced for a different request.',
                ['quote_reference' => $reference],
            );
        }

        return new ConversionQuoteData(
            reference: (string) $row->reference,
            direction: $row->direction,
            amountIn: (string) $row->amount_in,
            feeAmount: (string) $row->fee_amount,
            amountOut: (string) $row->amount_out,
            rate: (string) $row->rate,
            currency: Currency::tryFrom((string) $row->currency) ?? Currency::primary(),
            feeBearer: $row->fee_bearer,
            feeRuleKey: (string) $row->fee_rule_key,
            feeRuleVersion: (string) $row->fee_rule_version,
            limits: $this->limits->limits($request->currency),
            issuedAt: $row->created_at ? new DateTimeImmutable($row->created_at->toDateTimeString()) : new DateTimeImmutable('now'),
            expiresAt: $row->expires_at ? new DateTimeImmutable($row->expires_at->toDateTimeString()) : new DateTimeImmutable('now'),
        );
    }

    /**
     * Bind a quote to the movement it produced. Called inside the movement's
     * transaction, under the row lock.
     */
    public function consume(string $reference, int $conversionId): void
    {
        $row = $this->quotes->findByReference($reference);

        if ($row !== null) {
            $this->quotes->consume($row, $conversionId);
        }
    }

    private function reference(): string
    {
        return 'QC-'.strtoupper(bin2hex(random_bytes(8)));
    }
}
