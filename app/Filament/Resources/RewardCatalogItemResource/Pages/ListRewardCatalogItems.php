<?php

declare(strict_types=1);

namespace App\Filament\Resources\RewardCatalogItemResource\Pages;

use App\Filament\Resources\RewardCatalogItemResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

final class ListRewardCatalogItems extends ListRecords
{
    protected static string $resource = RewardCatalogItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
