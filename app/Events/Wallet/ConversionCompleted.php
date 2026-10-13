<?php

declare(strict_types=1);

namespace App\Events\Wallet;

use App\Enums\Currency;
use App\Enums\WalletConversionChannel;
use App\Enums\WalletConversionDirection;
use App\Models\WalletConversion;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Money finished moving between two wallets, in one committed transaction.
 *
 * WHEN THIS IS DISPATCHED
 * Strictly after the database transaction that moved the money has committed.
 * That ordering is the whole point of the event: a listener is allowed to write
 * an audit row, send an alert or notify an operator precisely because the state
 * it is describing is already durable. A listener that runs inside the money
 * transaction would hold the row locks for its own duration and, if it failed,
 * would drag a completed movement down with it.
 *
 * WHY IT CARRIES THE MODEL
 * Unlike ConversionRequested, this event is dispatched post-commit and is never
 * queued by this feature's own listeners, so passing the model is safe and
 * avoids every listener re-reading a row that the dispatcher already has in
 * hand. Identifiers and a scalar snapshot are copied alongside it so that a
 * listener which only needs the numbers never touches the database at all.
 */
final class ConversionCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly WalletConversion $conversion,
        public readonly int $userId,
        public readonly WalletConversionDirection $direction,
        public readonly string $amountIn,
        public readonly string $feeAmount,
        public readonly string $amountOut,
        public readonly Currency $currency,
        public readonly string $reference,
        // The channel is optional so that an event built by hand (a console
        // repair, a test) is still valid, but every event the platform raises
        // itself carries it: telemetry that cannot say where a conversion came
        // from cannot separate a member's mistake from an integration's.
        public readonly ?WalletConversionChannel $channel = null,
    ) {
    }

    /**
     * Project an event straight off a persisted conversion row.
     *
     * A static factory keeps the event's shape identical no matter which caller
     * dispatches it — the HTTP controller, the console command or a test — so a
     * listener can rely on `amountIn` being normalised to two decimals rather
     * than on whichever caller happened to build the object.
     */
    public static function fromConversion(WalletConversion $conversion): self
    {
        return new self(
            conversion: $conversion,
            userId: (int) $conversion->user_id,
            direction: $conversion->direction,
            amountIn: bcadd((string) $conversion->amount_in, '0', 2),
            feeAmount: bcadd((string) $conversion->fee_amount, '0', 2),
            amountOut: bcadd((string) $conversion->amount_out, '0', 2),
            currency: Currency::tryFrom((string) $conversion->currency) ?? Currency::primary(),
            reference: (string) $conversion->reference,
            // The model casts `channel` to the enum, but a row read with a raw
            // query or a partially hydrated model may still hand back the string,
            // so both shapes are accepted rather than trusting one cast.
            channel: $conversion->channel instanceof WalletConversionChannel
                ? $conversion->channel
                : WalletConversionChannel::tryFrom((string) $conversion->channel),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'conversion_id' => (int) $this->conversion->getKey(),
            'reference' => $this->reference,
            'user_id' => $this->userId,
            'direction' => $this->direction->value,
            'amount_in' => $this->amountIn,
            'fee_amount' => $this->feeAmount,
            'amount_out' => $this->amountOut,
            'currency' => $this->currency->value,
            'from_wallet_id' => (int) $this->conversion->from_wallet_id,
            'to_wallet_id' => (int) $this->conversion->to_wallet_id,
            'completed_at' => $this->conversion->completed_at?->format(DATE_ATOM),
        ];
    }
}
