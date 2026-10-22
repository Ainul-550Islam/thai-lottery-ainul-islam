<?php

declare(strict_types=1);

namespace App\Events;

use App\DTOs\Security\BotDecisionData;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BotBlocked
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $ipAddress,
        public readonly ?string $userId,
        public readonly string $requestPath,
        public readonly BotDecisionData $decision
    ) {}
}
