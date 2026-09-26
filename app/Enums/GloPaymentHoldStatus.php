<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a GloPrizePaymentHold row (GLO-12 winning-frozen-ticket hold).
 *
 *   active ──▶ cleared    (authorized freeze release / hold clearing)
 *   active ──▶ cancelled  (hold must never be silently deleted)
 *
 * While ACTIVE, payment execution is fail-closed. History is immutable: a
 * cleared hold row remains for audit.
 */
enum GloPaymentHoldStatus: string
{
    case Active = 'active';

    case Cleared = 'cleared';

    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'PAYMENT HOLD',
            self::Cleared => 'HOLD CLEARED',
            self::Cancelled => 'HOLD CANCELLED',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'danger',
            self::Cleared => 'green',
            self::Cancelled => 'gray',
        };
    }

    public function blocksPayment(): bool
    {
        return $this === self::Active;
    }

    public function isTerminal(): bool
    {
        return $this !== self::Active;
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Active => [self::Cleared, self::Cancelled],
            self::Cleared, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
