<?php

declare(strict_types=1);

namespace App\Filament\Resources\StripeDepositFollowupResource\Pages;

use App\Filament\Resources\StripeDepositFollowupResource;
use Filament\Resources\Pages\ListRecords;

final class ListStripeDepositFollowups extends ListRecords
{
    protected static string $resource = StripeDepositFollowupResource::class;

    public function getSubheading(): ?string
    {
        return 'Current account/configuration only. Routing hints are historical, not current risk clearance. No automatic processing or financial action. Unmatched events remain investigable; retry after original capture custody becomes available.';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
