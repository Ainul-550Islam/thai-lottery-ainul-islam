<?php

declare(strict_types=1);

namespace App\Filament\Resources\AmlRiskAssessmentResource\Pages;

use App\Filament\Resources\AmlRiskAssessmentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

/**
 * The assessment store, newest measurement first.
 *
 * No header action and no create: the lane measures, this screen reads. Everything an
 * operator can do here is a filter.
 */
class ListAmlRiskAssessments extends ListRecords
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
