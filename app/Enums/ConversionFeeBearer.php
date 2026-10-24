<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which side of the movement the fee reduces. Recorded on every quote, every
 * conversion row and every fee breakdown verbatim, so a dispute about who paid
 * the fee is answered by the row rather than by memory.
 */
enum ConversionFeeBearer: string
{
    case Source = 'source';
    case Destination = 'destination';

    public function label(): string
    {
        return match ($this) {
            self::Source => 'Source wallet',
            self::Destination => 'Destination wallet',
        };
    }

    public static function fromConfig(mixed $value): self
    {
        return self::tryFrom(is_string($value) ? strtolower(trim($value)) : '')
            ?? self::Source;
    }

    /**
     * Stable translation key for the member-facing conversion screens.
     *
     * The labels live in lang/<locale>/wallet_conversion.php next to every other
     * string this lane shows, so there is no second translation namespace to drift.
     */
    public function labelKey(): string
    {
        return "wallet_conversion.fee_bearer_{$this->value}";
    }
}
