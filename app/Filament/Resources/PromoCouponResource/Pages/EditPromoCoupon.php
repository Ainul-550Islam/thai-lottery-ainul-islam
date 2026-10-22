<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromoCouponResource\Pages;

use App\Filament\Resources\PromoCouponResource;
use Filament\Resources\Pages\EditRecord;

final class EditPromoCoupon extends EditRecord
{
    protected static string $resource = PromoCouponResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
