<?php

declare(strict_types=1);

namespace App\Services\Rewards\Fulfillers;

use App\Enums\RewardClaimStatus;
use App\Models\RewardCatalogItem;
use App\Models\RewardClaim;
use App\Models\User;

interface RewardFulfiller
{
    /**
     * @return array{status: RewardClaimStatus, financial_transaction_id: int|null, payload: array<string, mixed>}
     */
    public function fulfill(User $member, RewardCatalogItem $item, RewardClaim $claim): array;
}
