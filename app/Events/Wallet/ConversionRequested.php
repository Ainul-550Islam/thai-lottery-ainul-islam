<?php

declare(strict_types=1);

namespace App\Events\Wallet;

use App\Enums\Currency;
use App\Enums\WalletConversionChannel;
use App\Enums\WalletConversionDirection;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A member asked to move value between their Cash and Win wallets.
 *
 * WHAT THIS EVENT IS FOR, AND WHAT IT IS EMPHATICALLY NOT FOR
 * It is an observability signal: it says a request arrived and what shape it
 * had. It is NOT a permission to move money. Nothing in the platform may treat
 * the arrival of this event as authorisation, because at the moment it is
 * dispatched the policy gates, the wallet locks, the rolling limits and the
 * affordability check have not all run yet. The only events that describe money
 * having moved are ConversionCompleted and ConversionReversed, both of which are
 * dispatched after a database transaction has committed.
 *
 * WHY IT CARRIES THE AMOUNT AND NOT THE USER MODEL
 * A listener that needs the member can look it up by id; a listener that
 * receives a model would keep it alive for the life of the listener and, if the
 * event were ever queued, would serialise a User row into the queue payload.
 * Financial events carry identifiers and scalars, nothing more.
 */
final class ConversionRequested
{
    use Dispatchable;

    public function __construct(
        public readonly int $userId,
        public readonly WalletConversionDirection $direction,
        public readonly string $amount,
        public readonly Currency $currency,
        public readonly WalletConversionChannel $channel,
        public readonly ?string $quoteReference = null,
        public readonly ?string $idempotencyKey = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'direction' => $this->direction->value,
            'amount' => $this->amount,
            'currency' => $this->currency->value,
            'channel' => $this->channel->value,
            'quote_reference' => $this->quoteReference,
            // The idempotency key is included because operators debugging a
            // duplicate-request complaint need to correlate the two attempts, but
            // it is a client-generated nonce and carries no secret of its own.
            'idempotency_key' => $this->idempotencyKey,
        ];
    }
}
