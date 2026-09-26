<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\GloFreezeStatus;
use App\Models\GloTicketFreeze;
use App\Services\Lottery\GloTicketFreezeService;
use App\Support\Admin\AdminAccess;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Filament\Notifications\Notification;

/**
 * Filament page — GLO ticket freeze cases (GLO-11).
 *
 * Timeline/status of freeze cases with transition actions that call
 * GloTicketFreezeService only (no bypass, no direct status writes from the
 * form). Evidence is shown as reference IDs/hashes — raw documents are not
 * rendered here.
 */
class GloTicketFreezePage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-lock-closed';

    protected static ?string $navigationGroup = 'Lottery';

    protected static ?string $navigationLabel = 'GLO Ticket Freezes';

    protected static ?string $title = 'GLO Ticket Freeze Cases';

    protected static ?int $navigationSort = 6;

    protected static string $view = 'filament.pages.glo-ticket-freeze-page';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public ?string $selectedCase = null;

    public static function canAccess(): bool
    {
        return AdminAccess::currentAny([
            AdminAccess::REQUEST_GLO_FREEZES,
            AdminAccess::REVIEW_GLO_FREEZES,
            AdminAccess::VIEW_AUDIT_LOGS,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('status')
                    ->label('Status filter')
                    ->options(array_column(GloFreezeStatus::cases(), 'label', 'value'))
                    ->reactive()
                    ->afterStateUpdated(fn () => $this->dispatch('refresh')),
                TextInput::make('search')
                    ->label('Case / ticket reference')
                    ->placeholder('GLOFZ-… or drawId-l6-123456'),
            ])
            ->statePath('data');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getFreezes(): array
    {
        $query = GloTicketFreeze::query()
            ->orderByDesc('id')
            ->limit(200);

        $status = (string) ($this->data['status'] ?? '');

        if ($status !== '') {
            $query->where('status', $status);
        }

        $search = trim((string) ($this->data['search'] ?? ''));

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('freeze_case_id', 'like', '%'.$search.'%')
                    ->orWhere('case_reference', 'like', '%'.$search.'%')
                    ->orWhere('ticket_number', 'like', '%'.$search.'%');
            });
        }

        return $query->get()
            ->map(fn (GloTicketFreeze $freeze): array => [
                'id' => $freeze->getKey(),
                'freeze_case_id' => $freeze->freeze_case_id,
                'status' => $freeze->status->value,
                'status_label' => $freeze->status->label(),
                'color' => $freeze->status->color(),
                'ticket_reference' => $freeze->ticket?->ticket_reference,
                'ticket_number' => $freeze->ticket_number,
                'product' => $freeze->product,
                'case_reference' => $freeze->case_reference,
                'authority' => $freeze->requesting_authority,
                'evidence_reference' => $freeze->evidence_reference,
                'evidence_document_id' => $freeze->evidence_document_id,
                'requested_at' => $freeze->requested_at?->toDateTimeString(),
                'effective_at' => $freeze->effective_at?->toDateTimeString(),
                'released_at' => $freeze->released_at?->toDateTimeString(),
                'expiry_at' => $freeze->expiry_at?->toDateTimeString(),
                'resolution_reason' => $freeze->resolution_reason,
                'can_review' => in_array($freeze->status, [GloFreezeStatus::Requested, GloFreezeStatus::UnderReview], true)
                    && AdminAccess::current(AdminAccess::REVIEW_GLO_FREEZES),
                'can_approve' => $freeze->status === GloFreezeStatus::UnderReview
                    && AdminAccess::current(AdminAccess::REVIEW_GLO_FREEZES),
                'can_release' => $freeze->status === GloFreezeStatus::Frozen
                    && AdminAccess::current(AdminAccess::REVIEW_GLO_FREEZES),
            ])
            ->toArray();
    }

    public function transition(string $caseId, string $action, ?string $reason = null): void
    {
        $actor = AdminAccess::operator();

        if ($actor === null || ! AdminAccess::allows($actor, AdminAccess::REVIEW_GLO_FREEZES)) {
            Notification::make()
                ->title('Not permitted')
                ->body('Missing freeze review permission.')
                ->danger()
                ->send();

            return;
        }

        $freeze = GloTicketFreeze::query()->where('freeze_case_id', $caseId)->first();

        if ($freeze === null) {
            Notification::make()->title('Case not found')->danger()->send();

            return;
        }

        $service = app(GloTicketFreezeService::class);

        try {
            match ($action) {
                'review' => $service->startReview($freeze, $actor),
                'approve' => $service->approveToFrozen($freeze, $actor, $reason),
                'reject' => $service->reject($freeze, $actor, $reason ?? 'Rejected from panel'),
                'release' => $service->release($freeze, $actor, $reason ?? 'Released from panel'),
                default => throw new \InvalidArgumentException('Unknown action'),
            };
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Transition refused')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Freeze case updated')
            ->body($caseId.' → '.strtoupper($action))
            ->success()
            ->send();
    }

    /**
     * Freezes this page is allowed to display (same query as getFreezesProperty).
     *
     * @return \Illuminate\Support\Collection<int, GloTicketFreeze>
     */
    public function getTimeline(GloTicketFreeze $freeze)
    {
        return collect([
            ['at' => $freeze->requested_at, 'label' => 'Requested'],
            ['at' => $freeze->effective_at, 'label' => 'Effective (frozen)'],
            ['at' => $freeze->released_at, 'label' => 'Released'],
            ['at' => $freeze->expired_at, 'label' => 'Expired'],
        ]);
    }
}
