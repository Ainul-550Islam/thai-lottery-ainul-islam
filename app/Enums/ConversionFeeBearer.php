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
}
