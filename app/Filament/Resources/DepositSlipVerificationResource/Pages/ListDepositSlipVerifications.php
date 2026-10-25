<?php

declare(strict_types=1);

namespace App\Filament\Resources\DepositSlipVerificationResource\Pages;

use App\Filament\Resources\DepositSlipVerificationResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

final class ListDepositSlipVerifications extends ListRecords
{
    protected static string $resource = DepositSlipVerificationResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
