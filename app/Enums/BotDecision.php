<?php

declare(strict_types=1);

namespace App\Enums;

enum BotDecision: string
{
    case ALLOW = 'allow';
    case CHALLENGE = 'challenge';
    case THROTTLE = 'throttle';
    case REVIEW = 'review';
    case BLOCK = 'block';

    public function isPermitted(): bool
    {
        return $this === self::ALLOW;
    }

    public function isBlocked(): bool
    {
        return $this === self::BLOCK;
    }
}
