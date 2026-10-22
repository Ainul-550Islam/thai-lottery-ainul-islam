<?php

declare(strict_types=1);

namespace App\DTOs\Transfer;

use App\Enums\Currency;
use App\Enums\TransferDirection;

/**
 * An immutable price preview for one transfer direction and amount.
 *
 * WHY A DTO AND NOT AN ARRAY
 * A quote is what the player agrees to. Every field is a decimal STRING, so no
 * consumer can accidentally format a float and show the player a number that
 * differs in the last cent from what the engine will actually charge. The type
 * system enforces that discipline better than a docblock on an array key.
 *
 * NOTHING HERE IS AUTHORITATIVE.
 * A quote is a preview computed outside any lock. Balances move between quoting
 * and submitting, so BalanceTransferService recomputes the fee and re-checks
 * every limit inside the locked transaction. This object exists to tell the
 * player the truth as of a moment, not to reserve anything.
 */
final readonly class TransferQuote
{
    /**
     * @param  string  $amount  what arrives in the destination pocket
     * @param  string  $fee  what the lane charges on top
     * @param  string  $totalDebited  amount + fee, i.e. what leaves the source
     * @param  string  $sourceAvailable  spendable source balance right now
     * @param  string  $destinationBalance  destination balance right now
     * @param  string  $destinationAfter  what the destination would hold
     * @param  string  $feeRate  resolved fraction of 1 ('0.010000' = 1%)
     * @param  list<string>  $blockers  machine codes that would refuse this quote
     */
    private function __construct(
        public TransferDirection $direction,
        public Currency $currency,
        public string $amount,
        public string $fee,
        public string $totalDebited,
        public string $sourceAvailable,
        public string $destinationBalance,
        public string $destinationAfter,
        public string $feeCategory,
        public string $feeRate,
        public string $minimum,
        public string $maximum,
        public bool $affordable,
        public array $blockers = [],
    ) {}

    public static function make(
        TransferDirection $direction,
        Currency $currency,
        string $amount,
        string $fee,
        string $sourceAvailable,
        string $destinationBalance,
        string $feeCategory,
        string $feeRate,
        string $minimum,
        string $maximum,
        array $blockers = [],
    ): self {
        $totalDebited = bcadd($amount, $fee, 2);

        return new self(
            direction: $direction,
            currency: $currency,
            amount: $amount,
            fee: $fee,
            totalDebited: $totalDebited,
            sourceAvailable: $sourceAvailable,
            destinationBalance: $destinationBalance,
            // The destination receives the amount, not the total: the fee is
            // charged on top and never arrives.
            destinationAfter: bcadd($destinationBalance, $amount, 2),
            feeCategory: $feeCategory,
            feeRate: $feeRate,
            minimum: $minimum,
            maximum: $maximum,
            // Affordability is the ONLY blocker computed here, because it is the
            // one that needs a balance. Limits and cooldown are re-checked under
            // lock at submit time and reported as their own codes.
            affordable: bccomp($sourceAvailable, $totalDebited, 2) >= 0,
            blockers: array_values($blockers),
        );
    }

    /**
     * Whether submitting this exact quote right now would be accepted.
     */
    public function isExecutable(): bool
    {
        return $this->affordable && $this->blockers === [];
    }

    /**
     * The wire shape.
     *
     * Both pocket balances are included because both belong to the player being
     * quoted; this DTO is never built for someone else's wallets.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'direction' => $this->direction->value,
            'direction_label' => $this->direction->label(),
            'from' => $this->direction->pocketNames()['from'],
            'to' => $this->direction->pocketNames()['to'],
            'currency' => $this->currency->value,
            'amount' => $this->amount,
            'fee' => $this->fee,
            'total_debited' => $this->totalDebited,
            'fee_category' => $this->feeCategory,
            'fee_rate' => $this->feeRate,
            'minimum' => $this->minimum,
            'maximum' => $this->maximum,
            'source_available' => $this->sourceAvailable,
            'destination_balance' => $this->destinationBalance,
            'destination_after' => $this->destinationAfter,
            'affordable' => $this->affordable,
            'executable' => $this->isExecutable(),
            'blockers' => $this->blockers,
        ];
    }
}
