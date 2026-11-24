<?php

declare(strict_types=1);

namespace App\Filament\Resources\AmlRiskAssessmentResource\Pages;

use App\Filament\Resources\AmlRiskAssessmentResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

/**
 * One measurement, frozen.
 *
 * No edit and no re-measure action: re-measuring is the lane's job and it supersedes the row,
 * it does not correct it. What the operator gets here is the exact evidence behind a decision
 * that was already taken.
 */
class ViewAmlRiskAssessment extends ViewRecord
{
    protected static string $resource = AmlRiskAssessmentResource::class;

    /**
     * @return array<int, Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
