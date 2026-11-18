<?php

declare(strict_types=1);

namespace App\Filament\Resources\ComplianceAttestationResource\Pages;

use App\Filament\Resources\ComplianceAttestationResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

/**
 * The attestation list. Expired and revoked rows stay visible on purpose: an
 * operator needs to see that a badge went dark and when.
 */
class ListComplianceAttestations extends ListRecords
{
    protected static string $resource = ComplianceAttestationResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('Record evidence')
                ->url(static::getResource()::getUrl('create')),
        ];
    }
}
