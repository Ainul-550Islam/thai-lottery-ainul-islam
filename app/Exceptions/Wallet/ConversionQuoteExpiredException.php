<?php

declare(strict_types=1);

namespace App\Exceptions\Wallet;

use App\Enums\WalletConversionRejectionReason;
use DateTimeImmutable;
use DateTimeInterface;
use Throwable;

/**
 * The quote an execution referenced is no longer valid.
 *
 * WHY EXPIRY IS ENFORCED RATHER THAN IGNORED
 * A quote is a price the member was shown and, implicitly, agreed to. Two
 * things can invalidate it: time (config/conversions.php sets the TTL — the
 * window in which the displayed numbers are still the numbers that will be
 * charged) and change (the public fee schedule is versioned; a rate revision
 * between quote and execution would make the agreed price wrong).
 *
 * Silently re-pricing at execution is the one response that is never
 * acceptable, because it charges an amount the member never saw. Refusing with
 * this exception forces the client to re-quote and re-display, which is why
 * the exception carries both the stale reference and the exact expiry instant:
 * the UI can say "that quote expired 40 seconds ago; here is the new one",
 * which is a sentence a member can act on.
 */
final class ConversionQuoteExpiredException extends ConversionException
{
    public const ERROR_CODE = 'quote_expired';

    /**
     * The instants are stored as immutable values but ACCEPTED as anything that
     * implements `DateTimeInterface`.
     *
     * WHY THE TWO TYPES DO NOT MATCH
     * Eloquent hands a `Carbon` back from every date cast, and Carbon implements
     * `DateTimeInterface` but is not a `DateTimeImmutable`. When the parameters
     * were typed `DateTimeImmutable`, an ordinary expired quote — the single most
     * likely refusal in the whole feature — arrived as a Carbon and turned into a
     * TypeError, which surfaces to the member as a 500 on a refusal that should
     * have been a sentence. Accepting the interface and converting once, in one
     * place, is the only version of this that cannot fail on a date cast.
     */
    private readonly string $quoteReference;

    private readonly DateTimeImmutable $expiredAt;

    private readonly DateTimeImmutable $attemptedAt;

    private readonly ?string $currentRuleVersion;

    private readonly ?string $quotedRuleVersion;

    public function __construct(
        string $quoteReference,
        DateTimeInterface $expiredAt,
        ?DateTimeInterface $attemptedAt = null,
        ?string $currentRuleVersion = null,
        ?string $quotedRuleVersion = null,
        ?Throwable $previous = null,
    ) {
        $this->quoteReference = $quoteReference;
        $this->expiredAt = self::immutable($expiredAt);
        $this->attemptedAt = self::immutable($attemptedAt ?? new \DateTimeImmutable());
        $this->currentRuleVersion = $currentRuleVersion;
        $this->quotedRuleVersion = $quotedRuleVersion;

        $expiredAt = $this->expiredAt;
        $attemptedAt = $this->attemptedAt;

        parent::__construct(
            sprintf(
                'Quote %s expired at %s and cannot be executed; request a fresh quote.',
                $quoteReference,
                $expiredAt->format(DATE_ATOM),
            ),
            self::ERROR_CODE,
            [
                'quote_reference' => $quoteReference,
                'expired_at' => $expiredAt->format(DATE_ATOM),
                'attempted_at' => $attemptedAt->format(DATE_ATOM),
                'seconds_late' => max(0, $attemptedAt->getTimestamp() - $expiredAt->getTimestamp()),
                'quoted_rule_version' => $quotedRuleVersion,
                'current_rule_version' => $currentRuleVersion,
            ],
            $previous,
        );
    }

    public static function forReference(
        string $quoteReference,
        DateTimeInterface $expiredAt,
        ?DateTimeInterface $attemptedAt = null,
        ?Throwable $previous = null,
    ): self {
        return new self($quoteReference, $expiredAt, $attemptedAt, previous: $previous);
    }

    /**
     * The fee schedule was revised inside the quote's lifetime, so the agreed
     * price is no longer the published price even if the clock has not run out.
     */
    public static function forSupersededRule(
        string $quoteReference,
        DateTimeInterface $expiredAt,
        string $quotedRuleVersion,
        string $currentRuleVersion,
        ?DateTimeInterface $attemptedAt = null,
        ?Throwable $previous = null,
    ): self {
        return new self(
            $quoteReference,
            $expiredAt,
            $attemptedAt,
            $currentRuleVersion,
            $quotedRuleVersion,
            $previous,
        );
    }

    /**
     * One conversion point for every date the exception carries: DB casts hand
     * back Carbon, `now()` hands back Carbon, a caller may hand back a plain
     * `DateTime`, and everything downstream of this line is a single known type.
     */
    private static function immutable(DateTimeInterface $at): DateTimeImmutable
    {
        return $at instanceof DateTimeImmutable
            ? $at
            : DateTimeImmutable::createFromInterface($at);
    }

    public function quoteReference(): string
    {
        return $this->quoteReference;
    }

    public function expiredAt(): DateTimeInterface
    {
        return $this->expiredAt;
    }

    /**
     * True when the refusal is caused by a tariff revision rather than by the
     * clock — the operator-facing distinction between "the member was slow" and
     * "we changed the price underneath them".
     */
    public function isRuleSupersession(): bool
    {
        return $this->currentRuleVersion !== null && $this->quotedRuleVersion !== null;
    }

    public function rejectionReason(): ?WalletConversionRejectionReason
    {
        return WalletConversionRejectionReason::QuoteExpired;
    }
}
