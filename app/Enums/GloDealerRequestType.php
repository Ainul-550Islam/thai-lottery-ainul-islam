<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Documented my-GLO / e-Service dealer change request types.
 * Only these four public workflows exist — no private GLO APIs invented.
 */
enum GloDealerRequestType: string
{
    case Name = 'name';
    case Address = 'address';
    case Phone = 'phone';
    case SalesLocation = 'sales_location';

    public function label(): string
    {
        return match ($this) {
            self::Name => 'Name change',
            self::Address => 'Current address change',
            self::Phone => 'Phone change',
            self::SalesLocation => 'Selling location change',
        };
    }

    /**
     * Which profile field this type updates on approval.
     */
    public function profileField(): string
    {
        return match ($this) {
            self::Name => 'display_name',
            self::Address => 'address',
            self::Phone => 'phone',
            self::SalesLocation => 'sales_location',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
