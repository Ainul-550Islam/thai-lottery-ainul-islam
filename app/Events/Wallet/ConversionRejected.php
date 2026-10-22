<?php

declare(strict_types=1);

namespace App\Events\Wallet;

use App\Enums\Currency;
use App\Enums\WalletConversionChannel;
use App\Enums\WalletConversionDirection;
use App\Exceptions\Wallet\ConversionException;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A conversion was refused, and no money moved.
 *
 * WHY A REFUSAL IS AN EVENT WORTH BROADCASTING
 * A refusal is the only signal the platform produces about demand it could not
 * serve. Ten members in an hour told "insufficient balance" is normal business;
 * ten members in an hour told "fee rule missing" is an operator who disabled a
 * fee rule and broke the product. Both look identical in an error log; only the
 * refusal CODE makes them distinguishable, which is why the code — not the
 * human-readable message — is what this event carries as its identity.
 *
 * WHY THE ERROR CODE IS A STRING AND NOT AN ENUM
 * Not every refusal maps onto a case of WalletConversionRejectionReason: a
 * programming fault such as `conversion_same_wallet`, or an engine-level
 * `conversion_insufficient_balance` raised under the wallet lock, have no enum
 * counterpart. Forcing them into one would mean inventing an `other` case and
 * losing the distinction that makes the signal useful.
 *
 * NO MONEY STATE IS CARRIED
 * There is no balance, no transaction id and no wallet here, because none of
 * them changed. An event that hinted otherwise would invite a listener to write
 * something that reads like a movement.
 */
final class ConversionRejected
{
    use Dispatchable;

    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        public readonly int $userId,
        public readonly WalletConversionDirection $direction,
        public readonly string $amount,
        public readonly Currency $currency,
        public readonly WalletConversionChannel $channel,
        public readonly string $errorCode,
        public readonly array $context = [],
    ) {
    }

    public static function fromException(
        ConversionException $exception,
        int $userId,
        WalletConversionDirection $direction,
        string $amount,
        Currency $currency,
        WalletConversionChannel $channel,
    ): self {
        return new self(
            userId: $userId,
            direction: $direction,
            amount: $amount,
            currency: $currency,
            channel: $channel,
            errorCode: $exception->errorCode() ?? ConversionException::ERROR_CODE,
            context: $exception->context(),
        );
    }

    /**
     * True when the member could resolve this themselves — top up, verify their
     * identity, or wait out a limit. Listeners use it to decide whether a
     * member-facing message is appropriate at all.
     */
    public function isMemberCorrectable(): bool
    {
        return in_array($this->errorCode, [
            'insufficient_balance',
            'conversion_insufficient_balance',
            'below_minimum',
            'amount_below_minimum',
            'conversion_below_minimum',
            'daily_limit_exceeded',
            'monthly_limit_exceeded',
            'kyc_not_verified',
            'self_exclusion_active',
            'wallet_locked',
        ], true);
    }

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
            'error_code' => $this->errorCode,
            'context' => $this->context,
        ];
    }
}
