<?php

declare(strict_types=1);

namespace App\Filament\Resources\DepositSlipVerificationResource\Pages;

use App\Filament\Resources\DepositSlipVerificationResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

final class ViewDepositSlipVerification extends ViewRecord
{
    protected static string $resource = DepositSlipVerificationResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            DepositSlipVerificationResource::approvePageAction(),
            DepositSlipVerificationResource::rejectPageAction(),
        ];
    }
}
