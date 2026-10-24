<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The lifecycle of a conversion row.
 *
 * Quoted exists for rows created pre-execution; the member-facing quote itself
 * lives in wallet_conversion_quotes and is bound to the movement only when it
 * executes. Completed is the only state money has moved in. Reversed marks an
 * original whose movement was undone by a linked reversal row — the reversal
 * itself completes normally, because it is a movement.
 */
enum WalletConversionStatus: string
{
    case Quoted = 'quoted';
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Reversed = 'reversed';
    case Failed = 'failed';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Quoted => 'Quoted',
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::Reversed => 'Reversed',
            self::Failed => 'Failed',
            self::Expired => 'Expired',
        };
    }

    public function labelKey(): string
    {
        return 'wallet_conversion.status_'.$this->value;
    }

    public function isSuccessful(): bool
    {
        return $this === self::Completed;
    }

    public function isSettled(): bool
    {
        return in_array($this, self::settledStates(), true);
    }

    /**
     * States in which the movement is final: money moved and is not expected
     * to move again without a separate, linked movement.
     *
     * @return list<self>
     */
    public static function settledStates(): array
    {
        return [self::Completed, self::Reversed];
    }

    /**
     * The transitions a row may legally make. Everything else is a programming
     * error and is refused loudly rather than written to the table.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Quoted => [self::Pending, self::Processing, self::Failed, self::Expired],
            self::Pending => [self::Processing, self::Failed, self::Expired],
            self::Processing => [self::Completed, self::Failed],
            self::Completed => [self::Reversed],
            self::Reversed, self::Failed, self::Expired => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }
}
