<?php

declare(strict_types=1);

namespace App\Filament\Resources\DataSubjectRequestResource\Pages;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Filament\Resources\DataSubjectRequestResource;
use App\Filament\Resources\DataSubjectRequestResource\DataSubjectRequestActions;
use App\Models\DataSubjectRequest;
use App\Services\Audit\AuditLogService;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;

/**
 * One request, its timeline and the decisions available right now.
 *
 * THE PAGE IS AUDITED. This screen is the only place a staff member can read the
 * requester's decrypted description, and "who looked at whose complaint" is
 * exactly the question a supervisory authority asks. So opening the record writes
 * one audit row naming the operator and the reference - not the content, not the
 * address.
 *
 * The actions are the same declarations the queue uses, so a state that cannot
 * be reached from the list cannot be reached from the record either. There is no
 * EditAction: the lifecycle moves through the service's transition table, and
 * the requester's own words are shown, not edited.
 */
final class ViewDataSubjectRequest extends ViewRecord
{
    protected static string $resource = DataSubjectRequestResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return DataSubjectRequestActions::forPage();
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $subject = $this->getRecord();

        if (! $subject instanceof DataSubjectRequest) {
            return;
        }

        app(AuditLogService::class)->log(
            userId: Auth::id(),
            action: AuditAction::DataExport,
            riskLevel: RiskLevel::Medium,
            auditable: $subject,
            description: 'A staff member opened a privacy request record, including its decrypted details.',
            metadata: [
                'reference' => $subject->reference,
                'type' => $subject->type->value,
                'status' => $subject->status->value,
            ],
        );
    }
}
