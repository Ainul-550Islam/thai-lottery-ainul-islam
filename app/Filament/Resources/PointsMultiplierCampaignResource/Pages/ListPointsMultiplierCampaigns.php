<?php

declare(strict_types=1);

namespace App\Filament\Resources\PointsMultiplierCampaignResource\Pages;

use App\Filament\Resources\PointsMultiplierCampaignResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

/**
 * The campaign list. Overlapping campaigns are legal under the highest-only rule,
 * so this screen does not block them — it shows the windows side by side so an
 * accidental second campaign is visible.
 */
class ListPointsMultiplierCampaigns extends ListRecords
{
    protected static string $resource = PointsMultiplierCampaignResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('New campaign')
                ->url(static::getResource()::getUrl('create')),
        ];
    }
}
