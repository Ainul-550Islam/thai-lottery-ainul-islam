<?php

declare(strict_types=1);

namespace App\Filament\Resources\KycDocumentResource\Pages;

use App\Filament\Resources\KycDocumentResource;
use App\Filament\Resources\KycDocumentResource\KycDocumentActions;
use App\Models\KycDocument;
use App\Services\Audit\AuditLogService;
use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;

/**
 * One document, its lifecycle, and the decisions available now.
 *
 * THE PAGE IS AUDITED. Opening this record is the moment a staff member can read somebody's
 * identity evidence — masked, but still personal data. "Who looked at whose passport" is a data
 * protection question, so the open writes one audit row naming the operator and the document id,
 * never the document contents.
 *
 * No edit form: the type, issuer and fingerprint are what the player submitted. The verbs are the
 * queue's own declarations, so a decision reachable on the list is the same decision here.
 */
class ViewKycDocument extends ViewRecord
{
    protected static string $resource = KycDocumentResource::class;

    /**
     * @return array<int, Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return KycDocumentActions::forPage();
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $document = $this->getRecord();

        if (! $document instanceof KycDocument) {
            return;
        }

        // `Auth::id()` is `int|string|null` because a model may key on a uuid; the audit column is
        // an int. Narrow it here rather than letting a string reach an int parameter.
        $actorId = Auth::id();

        app(AuditLogService::class)->log(
            userId: is_int($actorId) ? $actorId : (is_numeric($actorId) ? (int) $actorId : null),
            action: AuditAction::DataExport,
            riskLevel: RiskLevel::Medium,
            auditable: $document,
            description: 'A staff member opened a KYC document record (masked number, no bytes).',
            metadata: [
                'document_id' => (int) $document->getKey(),
                // The model casts this to KycDocumentType, so the enum arm is the only one; the
                // `instanceof` that used to be here could never be false.
                'document_type' => $document->document_type->value,
                'surface' => 'admin-panel',
            ],
        );
    }
}
