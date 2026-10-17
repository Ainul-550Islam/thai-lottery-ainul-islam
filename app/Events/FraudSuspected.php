<?php

declare(strict_types=1);

namespace App\Events;

use App\DTOs\Security\FraudDecisionData;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FraudSuspected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $eventType,
        public readonly string $userId,
        public readonly string $amount,
        public readonly string $currency,
        public readonly FraudDecisionData $decision
    ) {}
}
