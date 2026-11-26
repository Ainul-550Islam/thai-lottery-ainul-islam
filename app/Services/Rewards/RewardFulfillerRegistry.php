<?php

declare(strict_types=1);

namespace App\Services\Rewards;

use App\Enums\RewardClaimStatus;
use App\Enums\RewardType;
use App\Models\RewardCatalogItem;
use App\Models\RewardClaim;
use App\Models\User;
use App\Services\Rewards\Fulfillers\BonusCashFulfiller;
use App\Services\Rewards\Fulfillers\CashbackBoostFulfiller;
use App\Services\Rewards\Fulfillers\FreeBetFulfiller;
use App\Services\Rewards\Fulfillers\VipUpgradeFulfiller;

final class RewardFulfillerRegistry
{
    public function __construct(
        private readonly BonusCashFulfiller $bonusCash,
        private readonly CashbackBoostFulfiller $cashbackBoost,
        private readonly FreeBetFulfiller $freeBet,
        private readonly VipUpgradeFulfiller $vipUpgrade,
    ) {}

    /**
     * @return array{status: RewardClaimStatus, financial_transaction_id: int|null, payload: array<string, mixed>}
     */
    public function fulfill(User $member, RewardCatalogItem $item, RewardClaim $claim): array
    {
        return match ($item->type) {
            RewardType::BonusCash => $this->bonusCash->fulfill($member, $item, $claim),
            RewardType::CashbackBoost => $this->cashbackBoost->fulfill($member, $item, $claim),
            RewardType::FreeBet => $this->freeBet->fulfill($member, $item, $claim),
            RewardType::VipUpgrade => $this->vipUpgrade->fulfill($member, $item, $claim),
        };
    }
}
