<?php

declare(strict_types=1);

namespace App\DTOs\Wallet;

use App\Enums\ConversionFeeBearer;
use App\Enums\Currency;
use App\Enums\WalletConversionDirection;
use App\ValueObjects\ConversionLimits;
use DateTimeImmutable;

/**
 * A priced, non-binding conversion offer with an expiry.
 *
 * WHY A QUOTE IS A REAL OBJECT
 * The member must see the exact fee and the exact amount that will land in the
 * destination wallet BEFORE money moves. That preview has to be computed by the
 * same code path that will later charge the fee, otherwise the preview and the
 * charge can disagree — and a disagreement between a shown price and a charged
 * price is a consumer-protection defect, not a rounding bug.
 *
 * This object is therefore produced by the fee calculator and the quote service
 * from the same configuration the conversion service reads, carries its own
 * expiry, and is persisted (wallet_conversion_quotes) so that an execution
 * referencing a stale reference is refused with QuoteExpired instead of
 * silently re-priced.
 *
 * All monetary fields are exact decimal strings.
 */
final readonly class ConversionQuoteData
{
    public function __construct(
        public string $reference,
        public WalletConversionDirection $direction,
        public string $amountIn,
        public string $feeAmount,
        public string $amountOut,
        public string $rate,
        public Currency $currency,
        public ConversionFeeBearer $feeBearer,
        public string $feeRuleKey,
        public string $feeRuleVersion,
        public ConversionLimits $limits,
        public DateTimeImmutable $issuedAt,
        public DateTimeImmutable $expiresAt,
        public ?string $description = null,
    ) {
    }

    /**
     * Seconds of validity remaining at the given moment; never negative.
     */
    public function secondsRemaining(?DateTimeImmutable $now = null): int
    {
        $now ??= new DateTimeImmutable();

        return max(0, $this->expiresAt->getTimestamp() - $now->getTimestamp());
    }

    public function isExpired(?DateTimeImmutable $now = null): bool
    {
        return $this->secondsRemaining($now) === 0;
    }

    public function hasFee(): bool
    {
        return bccomp($this->feeAmount, '0', 2) === 1;
    }

    /**
     * True when the quoted arithmetic is internally consistent — the amounts a
     * client is about to accept must provably add up.
     */
    public function isConsistent(): bool
    {
        return bccomp(bcsub($this->amountIn, $this->feeAmount, 2), $this->amountOut, 2) === 0;
    }

    /**
     * The full record, for persistence, admin screens and the audit trail.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'reference' => $this->reference,
            'direction' => $this->direction->value,
            'from_wallet_type' => $this->direction->fromType()->value,
            'to_wallet_type' => $this->direction->toType()->value,
            'amount_in' => $this->amountIn,
            'fee_amount' => $this->feeAmount,
            'amount_out' => $this->amountOut,
            'rate' => $this->rate,
            'currency' => $this->currency->value,
            'fee_bearer' => $this->feeBearer->value,
            'fee_rule_key' => $this->feeRuleKey,
            'fee_rule_version' => $this->feeRuleVersion,
            'limits' => $this->limits->toArray(),
            'issued_at' => $this->issuedAt->format(DATE_ATOM),
            'expires_at' => $this->expiresAt->format(DATE_ATOM),
            'description' => $this->description,
        ];
    }

    /**
     * The member-facing projection. Deliberately identical in substance to
     * toArray(): a quote hides nothing from the person paying it. Internal
     * identifiers are absent, not redacted.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return $this->toArray();
    }
}
