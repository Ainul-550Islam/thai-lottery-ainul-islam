<?php

declare(strict_types=1);

namespace App\Events;

use App\DTOs\Security\FraudDecisionData;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FraudReviewRequired
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $fraudAlertId,
        public readonly string $eventType,
        public readonly string $userId,
        public readonly string $amount,
        public readonly FraudDecisionData $decision
    ) {}
}
