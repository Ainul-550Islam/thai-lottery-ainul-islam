<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromoCouponResource\Pages;

use App\Filament\Resources\PromoCouponResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

final class ListPromoCoupons extends ListRecords
{
    protected static string $resource = PromoCouponResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
