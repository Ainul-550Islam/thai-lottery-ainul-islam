<?php

declare(strict_types=1);

namespace App\Filament\Resources\ComplianceAttestationResource\Pages;

use App\Filament\Resources\ComplianceAttestationResource;
use Filament\Resources\Pages\EditRecord;

/**
 * Edit an unrevoked attestation.
 *
 * The same label guard runs on save, because an operator can edit a previously clean
 * label into an over-claiming one — the check at creation time would not catch that.
 */
class EditComplianceAttestation extends EditRecord
{
    protected static string $resource = ComplianceAttestationResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        ComplianceAttestationResource::assertPublicLabelIsPublishable(
            isset($data['public_label']) ? (string) $data['public_label'] : null
        );

        return $data;
    }
}
