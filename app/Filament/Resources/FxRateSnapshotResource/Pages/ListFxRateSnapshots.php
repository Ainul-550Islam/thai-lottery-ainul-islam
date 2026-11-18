<?php

declare(strict_types=1);

namespace App\Filament\Resources\FxRateSnapshotResource\Pages;

use App\Filament\Resources\FxRateSnapshotResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

/**
 * The rate list. A create button is offered because adding a rate is the whole
 * purpose of this screen; editing and deleting are not offered at all (see the
 * resource).
 */
class ListFxRateSnapshots extends ListRecords
{
    protected static string $resource = FxRateSnapshotResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('Record a rate')
                ->url(static::getResource()::getUrl('create')),
        ];
    }
}
