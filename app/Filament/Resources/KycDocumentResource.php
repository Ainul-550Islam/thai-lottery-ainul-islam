<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\KycDocumentType;
use App\Enums\KycDocumentVerificationStatus;
use App\Filament\Resources\KycDocumentResource\KycDocumentActions;
use App\Filament\Resources\KycDocumentResource\Pages;
use App\Models\KycDocument;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminFormat;
use App\Support\Admin\AdminPermission;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The KYC evidence inventory — every document, its lifecycle word, and the decision verbs.
 *
 * WHY THIS EXISTS
 *
 * Until now the panel could review KYC only through `KycReviewPage`, a coarse-status queue that
 * shows a handful of fields and decides with `Security\KycVerificationService::reviewDocument()`.
 * The authoritative per-document lane (`Compliance\KycDocumentService`) — the one that holds the
 * `verification_status` transitions, refuses expired evidence and stamps the verification
 * reference — had no screen at all, and neither did the inventory: expiry dates, issuers,
 * fingerprints and refusal reasons were invisible.
 *
 * WHAT THIS SCREEN REFUSES TO SHOW
 *
 * The document bytes, the storage path, the original filename and the raw document number are
 * not rendered. A KYC document is somebody's passport; a panel that prints the number into HTML
 * puts it into browser caches, screenshots and support tickets. What is shown is the masked
 * number (last four), the document type, the issuer country, the fingerprint and the file's
 * shape (mime, size) — enough to take a decision, not enough to impersonate.
 *
 * There is deliberately no download button: serving identity documents needs a signed, audited
 * response, and the legacy `admin/kyc/{token}/download` route on the executive dashboard is not
 * the one this screen should point at.
 *
 * WHAT IT WILL NOT DO
 *
 * No create (documents arrive from the upload lane, fingerprinted), no edit (the type, number and
 * fingerprint are what the player submitted), no delete (evidence is retained; the model is
 * soft-deleted for history, not for tidiness). Every decision goes through KycDocumentService.
 *
 * PERMISSIONS: 'kyc.view' to read, 'kyc.review' to reject/expire, 'kyc.approve' to verify.
 * Maker-checker (nobody decides their own evidence) is enforced by the document lane itself.
 */
class KycDocumentResource extends Resource
{
    protected static ?string $model = KycDocument::class;

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationGroup = 'Compliance';

    protected static ?string $navigationLabel = 'KYC documents';

    protected static ?string $modelLabel = 'KYC document';

    protected static ?string $pluralModelLabel = 'KYC documents';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'document_fingerprint';

    public static function canViewAny(): bool
    {
        return AdminAccess::permitsAny([
            AdminPermission::KYC_VIEW,
            AdminPermission::KYC_REVIEW,
            AdminPermission::KYC_APPROVE,
        ]);
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof KycDocument && static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $waiting = KycDocument::query()
            ->whereIn('verification_status', [
                KycDocumentVerificationStatus::Uploaded->value,
                KycDocumentVerificationStatus::Processing->value,
            ])
            ->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('submitted_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['user', 'reviewer']))
            ->columns([
                Tables\Columns\TextColumn::make('user.username')
                    ->label('Player')
                    ->searchable()
                    ->sortable()
                    ->description(fn (KycDocument $record): string => 'user #'.(int) $record->user_id),

                Tables\Columns\TextColumn::make('document_type')
                    ->label('Document')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (KycDocumentType $state): string => static::documentLabel($state)),

                Tables\Columns\TextColumn::make('document_number')
                    ->label('Number')
                    ->formatStateUsing(fn ($state): string => static::maskNumber((string) ($state ?? '')))
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('issuer_country')
                    ->label('Issuer')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('verification_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => static::statusLabel($state))
                    ->color(fn ($state): string => static::statusColor($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Expires')
                    ->formatStateUsing(fn ($state, KycDocument $record): string => $state === null
                        ? 'no expiry'
                        : AdminFormat::marketTime($state).($record->expires_at?->isPast() ? ' — lapsed' : ''))
                    ->color(fn ($state, KycDocument $record): string => $record->expires_at?->isPast() ? 'danger' : 'gray')
                    ->sortable(),

                Tables\Columns\TextColumn::make('submitted_at')
                    ->label('Submitted')
                    ->since()
                    ->tooltip(fn ($state): string => AdminFormat::marketTime($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('verified_at')
                    ->label('Decided')
                    ->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('reviewer.username')
                    ->label('Decided by')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('rejection_reason')
                    ->label('Refusal reason')
                    ->limit(60)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('document_fingerprint')
                    ->label('Fingerprint')
                    ->formatStateUsing(fn ($state): string => substr((string) $state, 0, 12).'…')
                    ->tooltip(fn ($state): string => (string) $state)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('verification_status')
                    ->label('Status')
                    ->options(fn (): array => collect(KycDocumentVerificationStatus::cases())
                        ->mapWithKeys(fn (KycDocumentVerificationStatus $status): array => [$status->value => $status->label()])
                        ->all())
                    ->multiple(),

                Tables\Filters\SelectFilter::make('document_type')
                    ->label('Document type')
                    ->options(fn (): array => collect(KycDocumentType::cases())
                        ->mapWithKeys(fn (KycDocumentType $type): array => [$type->value => static::documentLabel($type)])
                        ->all()),

                Tables\Filters\Filter::make('lapsed_expiry')
                    ->label('Evidence whose date has passed but the row still reads live')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereNotNull('expires_at')
                        ->where('expires_at', '<', now())
                        ->whereIn('verification_status', [
                            KycDocumentVerificationStatus::Uploaded->value,
                            KycDocumentVerificationStatus::Processing->value,
                            KycDocumentVerificationStatus::Verified->value,
                        ]))
                    ->toggle(),

                Tables\Filters\Filter::make('expiring_soon')
                    ->label('Expiring within 30 days')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereNotNull('expires_at')
                        ->whereBetween('expires_at', [now(), now()->addDays(30)]))
                    ->toggle(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\ActionGroup::make(KycDocumentActions::forTable())
                    ->label('Decide')
                    ->icon('heroicon-m-check-circle')
                    ->visible(fn (): bool => AdminAccess::permitsAny([
                        AdminPermission::KYC_REVIEW,
                        AdminPermission::KYC_APPROVE,
                    ])),
            ])
            ->bulkActions([])
            ->emptyStateHeading('No KYC documents')
            ->emptyStateDescription('Documents appear here when a player submits identity evidence; nothing can be typed in from this screen.');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Evidence')
                ->description('What the player submitted, as recorded. The number is masked and the bytes are not rendered anywhere in this panel — identity documents are not printed into HTML.')
                ->schema([
                    Infolists\Components\TextEntry::make('user.username')->label('Player'),
                    Infolists\Components\TextEntry::make('document_type')
                        ->label('Document')
                        ->badge()
                        ->formatStateUsing(fn (KycDocumentType $state): string => static::documentLabel($state)),
                    Infolists\Components\TextEntry::make('document_number')
                        ->label('Number')
                        ->state(fn (KycDocument $record): string => static::maskNumber((string) ($record->document_number ?? ''))),
                    Infolists\Components\TextEntry::make('issuer_country')->label('Issuer'),
                    Infolists\Components\TextEntry::make('file_shape')
                        ->label('File')
                        ->state(fn (KycDocument $record): string => sprintf('%s · %s KB', (string) $record->mime_type, number_format(((int) $record->file_size) / 1024, 1))),
                    Infolists\Components\TextEntry::make('document_fingerprint')
                        ->label('Fingerprint')
                        ->copyable()
                        ->placeholder('—'),
                ])
                ->columns(3),

            Infolists\Components\Section::make('Lifecycle')
                ->schema([
                    Infolists\Components\TextEntry::make('verification_status')
                        ->label('Status')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => static::statusLabel($state))
                        ->color(fn ($state): string => static::statusColor($state)),
                    Infolists\Components\TextEntry::make('submitted_at')
                        ->label('Submitted')
                        ->state(fn (KycDocument $record): string => AdminFormat::marketTime($record->submitted_at)),
                    Infolists\Components\TextEntry::make('expires_at')
                        ->label('Expires')
                        ->state(fn (KycDocument $record): string => $record->expires_at === null
                            ? 'no expiry recorded'
                            : AdminFormat::marketTime($record->expires_at).($record->expires_at->isPast() ? ' — lapsed' : '')),
                    Infolists\Components\TextEntry::make('verified_at')
                        ->label('Decided at')
                        ->state(fn (KycDocument $record): string => AdminFormat::marketTime($record->verified_at)),
                    Infolists\Components\TextEntry::make('reviewer.username')->label('Decided by')->placeholder('—'),
                    Infolists\Components\TextEntry::make('verification_reference')
                        ->label('Verification reference')
                        ->placeholder('—')
                        ->copyable(),
                    Infolists\Components\TextEntry::make('rejection_reason')
                        ->label('Refusal reason')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->columns(3),

            Infolists\Components\Section::make('What this state means')
                ->schema([
                    Infolists\Components\TextEntry::make('state_note')
                        ->hiddenLabel()
                        ->state(fn (KycDocument $record): string => static::describeStatus($record))
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function statusLabel(mixed $state): string
    {
        $status = $state instanceof KycDocumentVerificationStatus
            ? $state
            : KycDocumentVerificationStatus::tryFrom((string) $state);

        return $status?->label() ?? 'Unknown';
    }

    public static function statusColor(mixed $state): string
    {
        $status = $state instanceof KycDocumentVerificationStatus
            ? $state
            : KycDocumentVerificationStatus::tryFrom((string) $state);

        return match ($status) {
            KycDocumentVerificationStatus::Uploaded => 'warning',
            KycDocumentVerificationStatus::Processing => 'info',
            KycDocumentVerificationStatus::Verified => 'success',
            KycDocumentVerificationStatus::Rejected => 'danger',
            KycDocumentVerificationStatus::Expired => 'gray',
            default => 'gray',
        };
    }

    public static function describeStatus(KycDocument $record): string
    {
        $status = $record->verificationState();

        $lapsed = $record->expires_at !== null && $record->expires_at->isPast();

        $note = match ($status) {
            KycDocumentVerificationStatus::Uploaded => 'Registered and fingerprinted; nobody has read it yet.',
            KycDocumentVerificationStatus::Processing => 'Claimed by a reviewer; the decision is not taken.',
            KycDocumentVerificationStatus::Verified => 'Pronounced genuine evidence, under the reference above.',
            KycDocumentVerificationStatus::Rejected => 'Refused; the player must resubmit. The reason is shown to them.',
            KycDocumentVerificationStatus::Expired => 'Its own validity date passed and the evidence lapses.',
            default => 'The lifecycle word is not one this panel recognises; treat it as unverified.',
        };

        if ($lapsed && $status !== KycDocumentVerificationStatus::Expired) {
            $note .= ' Its expiry date has already passed, so it can no longer be verified — only rejected or pronounced expired.';
        }

        return $note;
    }

    public static function maskNumber(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return '—';
        }

        $length = strlen($trimmed);

        if ($length <= 4) {
            return str_repeat('•', $length);
        }

        return str_repeat('•', max(0, $length - 4)).substr($trimmed, -4);
    }

    public static function documentLabel(KycDocumentType $type): string
    {
        return ucwords(str_replace('_', ' ', $type->value));
    }

    /**
     * Filament keys each page by its route name and stores the routed Page object — not a
     * class-string. The old annotation here said the opposite, which is why the analyser
     * reported every one of these resources.
     *
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListKycDocuments::route('/'),
            'view' => Pages\ViewKycDocument::route('/{record}'),
        ];
    }
}
