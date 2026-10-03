<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\KycDocument;
use App\Services\Security\KycVerificationService;
use App\Support\Admin\AdminAccess;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Filament page — authorized operator KYC review (approve / reject / resubmit).
 *
 * All domain writes go through KycVerificationService::reviewDocument (which
 * audits). This page never writes the users.kyc_status column directly, never
 * renders raw document file paths to the browser, and refuses self-review.
 */
class KycReviewPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationGroup = 'Compliance';

    protected static ?string $navigationLabel = 'KYC Review';

    protected static ?string $title = 'KYC Document Review';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.kyc-review-page';

    /** @var array<string, mixed> */
    public ?array $data = ['status_filter' => 'pending'];

    public static function canAccess(): bool
    {
        return AdminAccess::currentAny([
            AdminAccess::MANAGE_USERS,
            AdminAccess::VIEW_AUDIT_LOGS,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('status_filter')
                    ->label('Status filter')
                    ->options([
                        'pending' => 'Pending',
                        'under_review' => 'Under review',
                        'verified' => 'Verified',
                        'rejected' => 'Rejected',
                        'expired' => 'Expired',
                        'all' => 'All',
                    ])
                    ->default('pending')
                    ->reactive()
                    ->afterStateUpdated(fn () => $this->dispatch('refresh')),
            ])
            ->statePath('data');
    }

    /**
     * Pending-first queue. File paths and raw document bytes are NEVER
     * returned — only ids, coarse type, status and timestamps.
     *
     * @return list<array<string, mixed>>
     */
    public function getDocuments(): array
    {
        $filter = (string) ($this->data['status_filter'] ?? 'pending');
        $query = KycDocument::query()->orderByDesc('created_at')->limit(200);

        if ($filter !== '' && $filter !== 'all') {
            $query->where('status', $filter);
        }

        return $query->get()
            ->map(static fn (KycDocument $doc): array => [
                'id' => (int) $doc->getKey(),
                'user_id' => (int) $doc->user_id,
                'document_type' => $doc->document_type,
                'status' => $doc->status instanceof \BackedEnum ? $doc->status->value : (string) $doc->status,
                'submitted_at' => $doc->created_at?->toDateTimeString(),
                'verified_at' => $doc->verified_at?->toDateTimeString(),
                // Masked only — never the national ID itself.
                'document_number_masked' => self::maskNumber((string) ($doc->document_number ?? '')),
                'is_self' => (int) $doc->user_id === (int) (auth()->id() ?? 0),
            ])
            ->all();
    }

    public function approve(int $documentId): void
    {
        $this->decide($documentId, true, null);
    }

    public function reject(int $documentId): void
    {
        $this->decide($documentId, false, 'Document rejected by compliance review.');
    }

    public function requestResubmission(int $documentId): void
    {
        // Resubmission = reject with a resubmit reason; the player may re-upload
        // because KycStatus::Rejected is in canSubmit().
        $this->decide($documentId, false, 'Resubmission requested by compliance.');
    }

    private function decide(int $documentId, bool $approved, ?string $reason): void
    {
        $operator = auth()->user();

        if ($operator === null) {
            Notification::make()->title('Unauthenticated')->danger()->send();

            return;
        }

        if (! AdminAccess::allows($operator, AdminAccess::MANAGE_USERS) && ! $operator->hasRole('super-admin')) {
            Notification::make()->title('Forbidden')->danger()->send();

            return;
        }

        $document = KycDocument::query()->find($documentId);

        if ($document === null) {
            Notification::make()->title('Document not found')->danger()->send();

            return;
        }

        // Four-eyes: a reviewer may never approve their own identity evidence.
        if ((int) $document->user_id === (int) $operator->getKey()) {
            Notification::make()
                ->title('Self-review forbidden')
                ->body('You cannot review your own KYC submission.')
                ->danger()
                ->send();

            return;
        }

        try {
            app(KycVerificationService::class)->reviewDocument(
                $document,
                $operator,
                $approved,
                $reason,
            );
            Notification::make()->title($approved ? 'Approved' : 'Rejected / resubmission requested')->success()->send();
        } catch (\Throwable $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    private static function maskNumber(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '—';
        }
        $len = strlen($trimmed);
        if ($len <= 4) {
            return str_repeat('•', $len);
        }

        return str_repeat('•', max(0, $len - 4)).substr($trimmed, -4);
    }
}
