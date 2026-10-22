<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromoCouponResource\Pages;

use App\Filament\Resources\PromoCouponResource;
use Filament\Resources\Pages\CreateRecord;

final class CreatePromoCoupon extends CreateRecord
{
    protected static string $resource = PromoCouponResource::class;
}
