<?php

declare(strict_types=1);

namespace App\Filament\Resources\ComplianceAttestationResource\Pages;

use App\Filament\Resources\ComplianceAttestationResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * Record evidence.
 *
 * TWO guards run before the row is written: the public label is scanned for a claim
 * the evidence cannot support, and the verifying operator is stamped so the row
 * answers "who checked this".
 */
class CreateComplianceAttestation extends CreateRecord
{
    protected static string $resource = ComplianceAttestationResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        ComplianceAttestationResource::assertPublicLabelIsPublishable(
            isset($data['public_label']) ? (string) $data['public_label'] : null
        );

        $data['verified_by_user_id'] = auth()->id();

        return $data;
    }
}
