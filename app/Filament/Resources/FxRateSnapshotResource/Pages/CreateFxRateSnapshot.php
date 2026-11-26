<?php

declare(strict_types=1);

namespace App\Filament\Resources\FxRateSnapshotResource\Pages;

use App\Filament\Resources\FxRateSnapshotResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * Record a rate. The entering operator is stamped so an audit can tell a
 * human-entered number from one a future importer wrote.
 */
class CreateFxRateSnapshot extends CreateRecord
{
    protected static string $resource = FxRateSnapshotResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['entered_by_user_id'] = auth()->id();

        return $data;
    }
}
