<?php

declare(strict_types=1);

namespace App\Filament\Resources\CashbackPolicyResource\Pages;

use App\Filament\Resources\CashbackPolicyResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

final class ListCashbackPolicies extends ListRecords
{
    protected static string $resource = CashbackPolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
