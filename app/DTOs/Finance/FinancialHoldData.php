<?php

declare(strict_types=1);

namespace App\DTOs\Finance;

use App\Enums\Currency;
use App\Exceptions\FinancialHoldException;
use Illuminate\Support\Carbon;

/**
 * Financial-hold identity.
 */
final readonly class FinancialHoldData
{
    public function __construct(
        public int $walletId,
        public string $amount,
        public string $currency,
        public string $reason,
        public string $sourceReference,
        public ?string $expiresAt = null,
    ) {}

    /**
     * @throws FinancialHoldException
     */
    public static function fromInput(
        int $walletId,
        string $amount,
        string $currency,
        string $reason,
        string $sourceReference,
        ?string $expiresAt = null,
    ): self {
        if (! extension_loaded('bcmath')) {
            throw new \RuntimeException('BCMath is required for exact monetary validation.');
        }

        $amt = trim($amount);
        $cur = strtoupper(trim($currency));
        $why = trim($reason);
        $src = strtoupper(trim($sourceReference));

        if ($walletId < 1) {
            throw FinancialHoldException::malformed(
                'the wallet handle must be a positive integer',
            );
        }

        if (preg_match('/^\d{1,18}(\.\d{1,2})?$/D', $amt) !== 1) {
            throw FinancialHoldException::malformed(
                'the amount must be a decimal string (money, never float)',
            );
        }

        $canonicalAmount = bcadd($amt, '0', 2);

        if (bccomp($canonicalAmount, '0', 2) !== 1) {
            throw FinancialHoldException::malformed(
                'the amount must be strictly positive',
            );
        }

        if (Currency::tryFrom($cur) === null) {
            throw FinancialHoldException::malformed(
                'the currency must be an enabled 3-letter code',
            );
        }

        if (strlen($why) < 8 || strlen($why) > 255) {
            throw FinancialHoldException::malformed(
                'the reason must be 8-255 characters (the judge deserves a whole sentence)',
            );
        }

        if (strlen($src) < 8 || strlen($src) > 64 || preg_match('/^[A-Z0-9:\-\._]+$/D', $src) !== 1) {
            throw FinancialHoldException::malformed(
                'the source reference must be a canonical 8-64 character token',
            );
        }

        $expiry = null;

        if ($expiresAt !== null) {
            $candidate = trim($expiresAt);

            try {
                $expiry = Carbon::parse($candidate)->utc()->toIso8601String();
            } catch (\Throwable) {
                throw FinancialHoldException::malformed(
                    'the expiry, if carried, must be an ISO-8601 instant',
                );
            }
        }

        return new self(
            walletId: $walletId,
            amount: $canonicalAmount,
            currency: $cur,
            reason: $why,
            sourceReference: $src,
            expiresAt: $expiry,
        );
    }

    /**
     * Revalidate and normalize even when constructed directly via `new FinancialHoldData(...)`.
     *
     * @throws FinancialHoldException
     */
    public function normalized(): self
    {
        return self::fromInput(
            walletId: $this->walletId,
            amount: $this->amount,
            currency: $this->currency,
            reason: $this->reason,
            sourceReference: $this->sourceReference,
            expiresAt: $this->expiresAt,
        );
    }

    /**
     * Deterministic hold identity: (wallet, source, amount, currency).
     */
    public function holdKey(): string
    {
        $canonicalAmount = preg_match('/^\d{1,18}(\.\d{1,2})?$/D', trim($this->amount)) === 1
            ? bcadd(trim($this->amount), '0', 2)
            : trim($this->amount);

        return hash('sha256', sprintf(
            'fin-hold:%d:%s:%s:%s',
            $this->walletId,
            strtoupper(trim($this->sourceReference)),
            $canonicalAmount,
            strtoupper(trim($this->currency)),
        ));
    }
}
