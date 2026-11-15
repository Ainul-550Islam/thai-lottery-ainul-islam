<?php

declare(strict_types=1);

namespace App\Events\Rewards;

use App\Models\RewardClaim;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class RewardClaimed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly RewardClaim $claim,
    ) {}
}
