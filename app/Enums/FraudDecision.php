<?php

declare(strict_types=1);

namespace App\Enums;

enum FraudDecision: string
{
    case ALLOW = 'allow';
    case STEP_UP = 'step_up';
    case HOLD_FOR_REVIEW = 'hold_for_review';
    case DENY = 'deny';

    public function requiresReview(): bool
    {
        return $this === self::HOLD_FOR_REVIEW;
    }

    public function isAllowed(): bool
    {
        return $this === self::ALLOW;
    }
}
