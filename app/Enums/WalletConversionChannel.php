<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a conversion was initiated. The idempotency scope is per user AND per
 * channel, so an operator retry can never collide with a member key of the
 * same value.
 */
enum WalletConversionChannel: string
{
    case Web = 'web';
    case Api = 'api';
    case Admin = 'admin';
    case Console = 'console';

    public function label(): string
    {
        return match ($this) {
            self::Web => 'Member web portal',
            self::Api => 'Member API',
            self::Admin => 'Admin panel',
            self::Console => 'Console',
        };
    }

    public function labelKey(): string
    {
        return 'wallet_conversion.channel_'.$this->value;
    }

    /**
     * Channels a member can initiate from. Admin and console are operator
     * channels and never appear on a member's own request.
     */
    public function isMemberChannel(): bool
    {
        return match ($this) {
            self::Web, self::Api => true,
            self::Admin, self::Console => false,
        };
    }

    public function isOperator(): bool
    {
        return ! $this->isMemberChannel();
    }
}
