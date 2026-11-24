<?php

declare(strict_types=1);

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

/**
 * The role index.
 *
 * The header action is the resource's own canCreate(), so an operator who may read
 * roles but not compose them sees the list without a button that would refuse them.
 */
class ListRoles extends ListRecords
{
    protected static string $resource = RoleResource::class;

    /**
     * @return array<int, Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->visible(fn (): bool => RoleResource::canCreate()),
        ];
    }
}
