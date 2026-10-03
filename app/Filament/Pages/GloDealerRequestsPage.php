<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\GloDealerRequestStatus;
use App\Exceptions\GloDealerException;
use App\Models\GloDealerChangeRequest;
use App\Services\Lottery\GloDealerChangeRequestService;
use App\Support\Admin\AdminAccess;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Filament page — GLO-15 dealer change requests (operator review only).
 * Decisions go through GloDealerChangeRequestService (never direct status writes).
 */
class GloDealerRequestsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-user-circle';

    protected static ?string $navigationGroup = 'Lottery';

    protected static ?string $navigationLabel = 'GLO Dealer Requests';

    protected static ?string $title = 'GLO Dealer Change Requests';

    protected static ?int $navigationSort = 7;

    protected static string $view = 'filament.pages.glo-dealer-requests-page';

    /** @var array<string, mixed> */
    public ?array $data = ['review_note' => ''];

    public ?string $selectedReference = null;

    public static function canAccess(): bool
    {
        return AdminAccess::currentAny([
            AdminAccess::REVIEW_GLO_DEALER_REQUESTS,
            AdminAccess::VIEW_AUDIT_LOGS,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Textarea::make('review_note')
                    ->label('Review note (optional)')
                    ->rows(2),
            ])
            ->statePath('data');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRequests(): array
    {
        return GloDealerChangeRequest::query()
            ->orderByDesc('submitted_at')
            ->limit(200)
            ->get()
            ->map(static fn (GloDealerChangeRequest $r): array => [
                'request_reference' => $r->request_reference,
                'dealer_id' => $r->dealer_id,
                'request_type' => $r->request_type->label(),
                'old_value' => $r->old_value,
                'requested_value' => $r->requested_value,
                'reason' => $r->reason,
                'status' => $r->status->label(),
                'status_value' => $r->status->value,
                'reviewed_by' => $r->reviewed_by,
                'timeline' => trim(($r->submitted_at?->format('Y-m-d H:i') ?? '').' → '.($r->reviewed_at?->format('Y-m-d H:i') ?? '').' → '.($r->decided_at?->format('Y-m-d H:i') ?? '')),
                'audit_fingerprint' => $r->audit_fingerprint,
            ])
            ->all();
    }

    public function review(string $reference, string $decision): void
    {
        if (! AdminAccess::allows(auth()->user(), AdminAccess::REVIEW_GLO_DEALER_REQUESTS)
            && ! auth()->user()?->hasRole('super-admin')) {
            Notification::make()->title('Forbidden')->danger()->send();

            return;
        }

        $row = GloDealerChangeRequest::query()->where('request_reference', $reference)->first();

        if ($row === null) {
            Notification::make()->title('Request not found')->danger()->send();

            return;
        }

        $service = app(GloDealerChangeRequestService::class);
        $operator = auth()->user();
        $note = (string) ($this->data['review_note'] ?? null);

        try {
            if ($row->status === GloDealerRequestStatus::Submitted) {
                $row = $service->startReview($row, $operator);
            }

            if ($decision === 'approve') {
                $service->approve($row, $operator, $note !== '' ? $note : null);
                Notification::make()->title('Request approved')->success()->send();
            } elseif ($decision === 'reject') {
                $service->reject($row, $operator, $note !== '' ? $note : null);
                Notification::make()->title('Request rejected')->success()->send();
            } else {
                Notification::make()->title('Unknown decision')->danger()->send();
            }
        } catch (GloDealerException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }
}
