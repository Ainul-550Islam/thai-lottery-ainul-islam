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

    public function label(): string
    {
        return match ($this) {
            self::Quoted => 'Quoted',
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::Reversed => 'Reversed',
            self::Failed => 'Failed',
        };
    }

    /**
     * Did money move because of this row?
     *
     * True for a completed conversion and for a completed reversal: both are
     * movements, and the reversal is the one that gives the money back. Deliberately
     * false for Reversed, which describes the ORIGINAL after it was undone — reading
     * that as success would let a reversed movement count as a movement in reports.
     */
    public function isSuccessful(): bool
    {
        return $this === self::Completed;
    }

    /**
     * Has this row's money movement happened and stayed put?
     *
     * This is the state the accounting proof insists on before it will accept a row
     * as evidence: a pending or processing conversion describes an intention, and a
     * failed one describes an intention that did not happen.
     */
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
            self::Quoted => [self::Pending, self::Processing, self::Failed],
            self::Pending => [self::Processing, self::Failed],
            self::Processing => [self::Completed, self::Failed],
            self::Completed => [self::Reversed],
            self::Reversed, self::Failed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Stable translation key for the member-facing conversion screens.
     *
     * The labels live in lang/<locale>/wallet_conversion.php next to every other
     * string this lane shows, so there is no second translation namespace to drift.
     */
    public function labelKey(): string
    {
        return "wallet_conversion.status_{$this->value}";
    }
}
