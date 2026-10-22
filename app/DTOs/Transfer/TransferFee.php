<?php

declare(strict_types=1);

namespace App\DTOs\Transfer;

use App\Enums\TransferDirection;

/**
 * The price of one transfer, resolved from the published fee schedule.
 *
 * WHY A VALUE OBJECT AND NOT AN ARRAY
 * A fee is three facts that must travel together: the amount charged, the rate it was
 * derived from, and the schedule row that produced it. Returning them as an
 * untyped array invites a caller to read two of the three, and a fee recorded without
 * its rate is a fee nobody can audit later. Carrying them in one immutable object
 * makes "store the price" a single operation that cannot be done halfway.
 *
 * THE VALUES ARE STRINGS, NEVER FLOATS.
 * $fee is at scale 2 because that is the scale of every money column. $rate is at
 * scale 6 because that is the scale FeesPageService resolves rates to; truncating it
 * to 2 would store '0.01' and lose the distinction between 1% and 1.005%, and
 * storing a rate that disagrees with the fee it produced would make the snapshot
 * useless for answering "was this priced correctly at the time".
 */
final readonly class TransferFee
{
    /**
     * Scale the fee schedule resolves rates to.
     */
    public const RATE_SCALE = 6;

    /**
     * Scale every money column in this schema uses.
     */
    public const AMOUNT_SCALE = 2;

    private function __construct(
        public TransferDirection $direction,
        public string $amount,
        public string $fee,
        public string $rate,
        public string $category,
    ) {}

    /**
     * @param  string  $amount  the value being moved, at scale 2
     * @param  string  $fee  what the schedule charged, at scale 2
     * @param  string  $rate  the fraction of 1 it was derived from, at scale 6
     * @param  string  $category  the config/fees.php row that produced it
     */
    public static function make(
        TransferDirection $direction,
        string $amount,
        string $fee,
        string $rate,
        string $category,
    ): self {
        return new self(
            direction: $direction,
            amount: bcadd($amount, '0', self::AMOUNT_SCALE),
            fee: bcadd($fee, '0', self::AMOUNT_SCALE),
            // Rescaled rather than passed through: resolveRate() returns the row's
            // own string ('0.0100'), and a snapshot has to be at a fixed scale to be
            // comparable across rows written at different times.
            rate: bcadd($rate, '0', self::RATE_SCALE),
            category: $category,
        );
    }

    /**
     * Whether anything was actually charged.
     *
     * A zero fee is a legitimate outcome - a lane priced at 0% - and the service uses
     * this to skip the fee ledger leg entirely. Posting a zero-value entry would add a
     * row to a table where every row is supposed to mean money moved.
     */
    public function isCharged(): bool
    {
        return bccomp($this->fee, '0', self::AMOUNT_SCALE) > 0;
    }

    /**
     * amount + fee: everything that leaves the source pocket.
     *
     * The fee model is on-top, so this is what the player is debited and the amount
     * that arrives is $this->amount unchanged. Computed here rather than at each call
     * site so there is exactly one place that knows the fee is added and not deducted.
     */
    public function totalDebited(): string
    {
        return bcadd($this->amount, $this->fee, self::AMOUNT_SCALE);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'direction' => $this->direction->value,
            'amount' => $this->amount,
            'fee' => $this->fee,
            'fee_rate' => $this->rate,
            'fee_category' => $this->category,
            'total_debited' => $this->totalDebited(),
        ];
    }
}
