<?php

declare(strict_types=1);

namespace App\Filament\Resources\WalletConversionResource\Pages;

use App\Filament\Resources\WalletConversionResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Actions\ViewAction;

/**
 * The conversion list page.
 *
 * WHAT THIS PAGE ADDS OVER THE RESOURCE
 * Almost nothing, and that is the point. The table, the filters and the infolist
 * all come from the resource, so there is exactly one place where the columns
 * are defined and exactly one place to review when somebody asks whether the
 * conversion screen can move money. The only thing configured here is the row
 * action: a view modal, which is read-only by construction.
 *
 * WHY THERE IS NO CREATE ACTION IN THE HEADER
 * Laravel Filament would render one if the resource allowed creating, and the
 * resource refuses. Adding an explicit null here as well would be noise; the
 * denial lives with the resource, where a future reader looks for it.
 *
 * WHY THE VIEW ACTION IS ADDED HERE RATHER THAN IN THE RESOURCE TABLE
 * Filament's ListRecords composes the table's actions; declaring the modal here
 * keeps the resource's `table()` method concerned with what the list shows, and
 * leaves how a list page presents a record to the page that presents it.
 */
class ListWalletConversions extends ListRecords
{
    protected static string $resource = WalletConversionResource::class;

    /**
     * @return array<int, ViewAction>
     */
    protected function getTableActions(): array
    {
        return [
            ViewAction::make()
                // The modal renders the resource's infolist. It carries no form,
                // so there is no field on this screen that could ever be saved.
                ->modalHeading(static fn ($record): string => 'Conversion '.$record->reference)
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close'),
        ];
    }

    public function getTitle(): string
    {
        return 'Cash ⇄ Win conversions';
    }
}
