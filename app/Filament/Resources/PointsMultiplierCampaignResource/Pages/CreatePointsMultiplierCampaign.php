<?php

declare(strict_types=1);

namespace App\Filament\Resources\PointsMultiplierCampaignResource\Pages;

use App\Filament\Resources\PointsMultiplierCampaignResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * Create a campaign. The author is stamped for the audit trail: a multiplier is a
 * money-adjacent policy decision and "who launched this" must be answerable.
 */
class CreatePointsMultiplierCampaign extends CreateRecord
{
    protected static string $resource = PointsMultiplierCampaignResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by_user_id'] = auth()->id();

        return $data;
    }
}
