<?php

declare(strict_types=1);

namespace App\Filament\Resources\ComplianceCaseResource\Pages;

use App\Filament\Resources\ComplianceCaseResource;
use App\Filament\Resources\ComplianceCaseResource\ComplianceCaseActions;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

/**
 * One investigation file.
 *
 * The header re-uses the table's lifecycle actions, so a move reachable from the queue is the
 * same move here — one declaration, one service call, one audit line. There is no EditAction:
 * the file's risk level and evidence fingerprint are stamped by the assessment lane, and the
 * investigator's own words only ever arrive through the service (escalation reason,
 * resolution note).
 */
class ViewComplianceCase extends ViewRecord
{
    protected static string $resource = ComplianceCaseResource::class;

    /**
     * @return array<int, Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        // The queue's own declarations. Filament binds this page's record to each action
        // (ViewRecord::configureAction), so the header and the row share one definition and
        // one service call.
        return ComplianceCaseActions::forPage();
    }
}
