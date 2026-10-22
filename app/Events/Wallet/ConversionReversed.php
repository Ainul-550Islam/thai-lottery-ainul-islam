<?php

declare(strict_types=1);

namespace App\Events\Wallet;

use App\Enums\Currency;
use App\Enums\WalletConversionChannel;
use App\Enums\WalletConversionDirection;
use App\Models\WalletConversion;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A completed conversion was undone by an operator, and both rows survive.
 *
 * WHY THE EVENT CARRIES TWO CONVERSIONS
 * A reversal is the only movement in this feature that produces two records: the
 * original, now marked `reversed`, and the reversal itself, which is a real
 * conversion row with its own reference and its own ledger postings. A listener
 * that only received the reversal could not answer "what did this undo?" without
 * re-reading the database, and a listener that only received the original could
 * not tell an operator what to search for in the ledger. Both are handed over.
 *
 * WHY THE REASON TRAVELS WITH IT
 * The reason is a required, human-authored field, and it is the single most
 * valuable thing in the whole record: six months later it is the difference
 * between "a supervisor fixed a mispriced promotion" and an unexplained pair of
 * movements. It is copied onto the event so that an alert or an audit export
 * never has to join back to the conversion row to render something meaningful.
 */
final class ConversionReversed
{
    use Dispatchable;

    public function __construct(
        public readonly WalletConversion $original,
        public readonly WalletConversion $reversal,
        public readonly int $userId,
        public readonly string $originalReference,
        public readonly string $reversalReference,
        public readonly string $amountIn,
        public readonly string $feeRefunded,
        public readonly Currency $currency,
        public readonly string $reason,
        // The direction and channel of the ORIGINAL movement, not of the undo.
        // A reversal is performed by an operator on the admin channel, so reading
        // these off the reversal row would tell every listener that the member
        // converted on the admin channel — and the member-facing channels are
        // exactly what the telemetry is used to separate. Nullable so an event
        // built by hand (a repair script, a test) is still constructible.
        public readonly ?WalletConversionDirection $direction = null,
        public readonly ?WalletConversionChannel $channel = null,
    ) {}

    public static function fromConversions(
        WalletConversion $original,
        WalletConversion $reversal,
        string $reason,
    ): self {
        return new self(
            original: $original,
            reversal: $reversal,
            userId: (int) $original->user_id,
            originalReference: (string) $original->reference,
            reversalReference: (string) $reversal->reference,
            amountIn: bcadd((string) $original->amount_in, '0', 2),
            // The refund is the ORIGINAL fee, not a fee on the reversal: undoing
            // a movement must not cost the member anything, so the full original
            // fee comes back from the revenue account it was booked to.
            feeRefunded: bcadd((string) $original->fee_amount, '0', 2),
            currency: Currency::tryFrom((string) $original->currency) ?? Currency::primary(),
            reason: $reason,
            direction: $original->direction instanceof WalletConversionDirection
                ? $original->direction
                : WalletConversionDirection::tryFrom((string) $original->direction),
            channel: $original->channel instanceof WalletConversionChannel
                ? $original->channel
                : WalletConversionChannel::tryFrom((string) $original->channel),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'original_conversion_id' => (int) $this->original->getKey(),
            'reversal_conversion_id' => (int) $this->reversal->getKey(),
            'original_reference' => $this->originalReference,
            'reversal_reference' => $this->reversalReference,
            'user_id' => $this->userId,
            'amount_in' => $this->amountIn,
            'fee_refunded' => $this->feeRefunded,
            'currency' => $this->currency->value,
            'reason' => $this->reason,
            'reversed_at' => $this->reversal->completed_at?->format(DATE_ATOM),
        ];
    }
}
