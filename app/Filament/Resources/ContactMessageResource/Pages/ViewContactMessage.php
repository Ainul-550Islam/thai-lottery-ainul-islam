<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContactMessageResource\Pages;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Filament\Resources\ContactMessageResource;
use App\Filament\Resources\ContactMessageResource\ContactMessageActions;
use App\Models\ContactMessage;
use App\Services\Audit\AuditLogService;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;

/**
 * One support message and everything that was done about it.
 *
 * THE PAGE IS AUDITED, for the same reason the privacy-request screen is: the
 * body of a contact form is a person's own words, sent in confidence, and this is
 * the only screen that shows them. The audit row records who opened which
 * reference - never the text, never the address.
 *
 * The timeline is read-only by construction: contact_message_events is
 * append-only at the model layer, so an operator can add a note or a reply but
 * cannot rewrite history. There is no EditAction - the visitor's words are
 * evidence, not a draft.
 */
final class ViewContactMessage extends ViewRecord
{
    protected static string $resource = ContactMessageResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return ContactMessageActions::forPage();
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $subject = $this->getRecord();

        if (! $subject instanceof ContactMessage) {
            return;
        }

        app(AuditLogService::class)->log(
            userId: Auth::id(),
            action: AuditAction::DataExport,
            riskLevel: RiskLevel::Low,
            auditable: $subject,
            description: 'A staff member opened a support message, including the sender\'s message body.',
            metadata: [
                'reference' => $subject->public_reference,
                'status' => $subject->status,
            ],
        );
    }
}
