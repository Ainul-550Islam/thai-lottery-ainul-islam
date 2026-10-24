<?php

declare(strict_types=1);

namespace App\DTOs\Wallet;

use App\Enums\Currency;
use App\Enums\WalletConversionChannel;
use App\Enums\WalletConversionDirection;
use App\Enums\WalletType;
use App\Exceptions\Wallet\ConversionException;
use App\Support\Wallet\ConversionCurrency;
use InvalidArgumentException;

/**
 * Everything a conversion request must state, resolved and type-safe.
 *
 * WHY THE DOMAIN DOES NOT READ THE HTTP REQUEST
 * The same movement is requested from a web form, a JSON API call, an operator
 * action in Filament and a console command. If the service read `request()`
 * directly, three of those four callers could not use it and the fourth would
 * silently define the contract. This object is the contract: every caller
 * builds one of these, and the service only ever sees validated values.
 *
 * The amount is carried as an exact decimal STRING. Converting it to a float at
 * any point in this pipeline is a defect, so the type makes that impossible.
 */
final readonly class ConversionRequestData
{
    /**
     * @param  array<string, scalar|null>  $metadata
     */
    public function __construct(
        public WalletConversionDirection $direction,
        public string $amount,
        public Currency $currency,
        public WalletConversionChannel $channel,
        public int $userId,
        public ?string $idempotencyKey = null,
        public ?string $quoteReference = null,
        public ?ConversionAuditContextData $auditContext = null,
        public array $metadata = [],
    ) {
        if ($this->userId <= 0) {
            throw new InvalidArgumentException('A conversion request must name the wallet owner; user id must be positive.');
        }

        if (preg_match('/^\d+(\.\d+)?$/', trim($this->amount)) !== 1) {
            throw new InvalidArgumentException(
                sprintf('A conversion amount must be a non-negative decimal string; [%s] was given.', $this->amount),
            );
        }
    }

    /**
     * Build from validated request/command input. Enum-ish fields accept either
     * their enum instance or their backing string, so an HTTP request array and
     * a console option array both work without a second factory.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $direction = $data['direction'] ?? null;

        if (! $direction instanceof WalletConversionDirection) {
            $direction = WalletConversionDirection::tryFromString(is_string($direction) ? $direction : null);
        }

        if (! $direction instanceof WalletConversionDirection) {
            throw ConversionException::failure(
                'conversion_direction_missing',
                'A conversion request must carry a direction of [cash_to_win] or [win_to_cash].',
                ['direction' => is_scalar($direction) ? (string) $direction : gettype($direction)],
            );
        }

        // A currency is never guessed. When the caller named one it must be a real
        // code, and when they did not the lane's configured currency is used — with no
        // fall back to THB, because that fallback is how a misconfigured USD lane
        // quietly starts moving THB money.
        $currency = array_key_exists('currency', $data)
            ? ConversionCurrency::parse($data['currency'])
            : ConversionCurrency::configured();

        $channel = $data['channel'] ?? WalletConversionChannel::Web;

        if (! $channel instanceof WalletConversionChannel) {
            $channel = WalletConversionChannel::tryFrom((string) $channel) ?? WalletConversionChannel::Web;
        }

        $auditContext = $data['audit_context'] ?? $data['auditContext'] ?? null;

        if (is_array($auditContext)) {
            $auditContext = ConversionAuditContextData::fromArray($auditContext, $channel);
        }

        return new self(
            direction: $direction,
            amount: trim((string) ($data['amount'] ?? '0')),
            currency: $currency,
            channel: $channel,
            userId: (int) ($data['user_id'] ?? $data['userId'] ?? 0),
            idempotencyKey: self::stringOrNull($data['idempotency_key'] ?? $data['idempotencyKey'] ?? null),
            quoteReference: self::stringOrNull($data['quote_reference'] ?? $data['quoteReference'] ?? null),
            auditContext: $auditContext instanceof ConversionAuditContextData ? $auditContext : null,
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
        );
    }

    /**
     * The wallet this request debits.
     */
    public function fromWalletType(): WalletType
    {
        return $this->direction->fromType();
    }

    /**
     * The wallet this request credits.
     */
    public function toWalletType(): WalletType
    {
        return $this->direction->toType();
    }

    public function hasIdempotencyKey(): bool
    {
        return $this->idempotencyKey !== null && trim($this->idempotencyKey) !== '';
    }

    public function hasQuote(): bool
    {
        return $this->quoteReference !== null && trim($this->quoteReference) !== '';
    }

    /**
     * A copy of this request bound to a freshly issued quote reference.
     */
    public function withQuoteReference(string $reference): self
    {
        return new self(
            direction: $this->direction,
            amount: $this->amount,
            currency: $this->currency,
            channel: $this->channel,
            userId: $this->userId,
            idempotencyKey: $this->idempotencyKey,
            quoteReference: $reference,
            auditContext: $this->auditContext,
            metadata: $this->metadata,
        );
    }

    /**
     * A copy of this request with the idempotency key replaced. Used when the
     * service derives a deterministic key from a member-visible reference.
     */
    public function withIdempotencyKey(?string $key): self
    {
        return new self(
            direction: $this->direction,
            amount: $this->amount,
            currency: $this->currency,
            channel: $this->channel,
            userId: $this->userId,
            idempotencyKey: $key,
            quoteReference: $this->quoteReference,
            auditContext: $this->auditContext,
            metadata: $this->metadata,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'direction' => $this->direction->value,
            'from_wallet_type' => $this->fromWalletType()->value,
            'to_wallet_type' => $this->toWalletType()->value,
            'amount' => $this->amount,
            'currency' => $this->currency->value,
            'channel' => $this->channel->value,
            'user_id' => $this->userId,
            'idempotency_key' => $this->idempotencyKey,
            'quote_reference' => $this->quoteReference,
            'audit_context' => $this->auditContext?->toArray(),
            'metadata' => $this->metadata,
        ];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
