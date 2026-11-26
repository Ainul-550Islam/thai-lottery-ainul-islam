<?php

declare(strict_types=1);

namespace App\Filament\Resources\PointsMultiplierCampaignResource\Pages;

use App\Filament\Resources\PointsMultiplierCampaignResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

/**
 * Edit a campaign.
 *
 * There is no delete action: a campaign that already paid points is referenced by
 * the metadata of the ledger rows it affected, and deleting it would leave those
 * rows citing a campaign that no longer exists. Deactivating is the reversible,
 * auditable alternative.
 */
class EditPointsMultiplierCampaign extends EditRecord
{
    protected static string $resource = PointsMultiplierCampaignResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
