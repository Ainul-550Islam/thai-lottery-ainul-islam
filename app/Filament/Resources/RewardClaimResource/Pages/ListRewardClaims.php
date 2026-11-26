<?php

declare(strict_types=1);

namespace App\Filament\Resources\RewardClaimResource\Pages;

use App\Filament\Resources\RewardClaimResource;
use Filament\Resources\Pages\ListRecords;

final class ListRewardClaims extends ListRecords
{
    protected static string $resource = RewardClaimResource::class;
}
