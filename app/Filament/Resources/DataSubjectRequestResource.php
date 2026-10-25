<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\PrivacyRequestStatus;
use App\Enums\PrivacyRequestType;
use App\Filament\Resources\DataSubjectRequestResource\DataSubjectRequestActions;
use App\Filament\Resources\DataSubjectRequestResource\Pages;
use App\Models\DataSubjectRequest;
use App\Services\Privacy\PrivacyRequestService;
use App\Support\Admin\AdminAccess;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The compliance desk's view of data-subject requests.
 *
 * =========================================================================
 * WHAT THIS SCREEN IS FOR
 * =========================================================================
 * One question, asked urgently: which promises are we about to break? So the
 * default order is the due date, the first tab is "overdue", and the navigation
 * badge counts requests whose clock has already run out. Everything else -
 * filtering, the timeline, the export state - exists to answer "what did we do
 * about this one".
 *
 * =========================================================================
 * WHAT IT DELIBERATELY CANNOT DO
 * =========================================================================
 * - No create. A request exists because a person asked; an operator typing one
 *   in would have no proven owner, which is the property the whole workflow
 *   rests on.
 * - No edit form. Free text, the requester's address and the lifecycle are not
 *   form fields: the address arrived from a verified mail, and the state moves
 *   through the service's transition table, never through a text input.
 * - No delete, anywhere, for anybody. Deleting the row would erase the evidence
 *   that the platform answered - or failed to answer - a statutory request.
 *   Retention handles archives; it never handles this table.
 * - No address in the table. The requester's address is decrypted only on the
 *   view screen of a permitted operator, because a list projection is the kind
 *   of thing that ends up in a screenshot.
 *
 * The permission split mirrors the rest of the panel: reading needs the review
 * permissions (audit logs or manage users), acting needs manage users.
 */
final class DataSubjectRequestResource extends Resource
{
    protected static ?string $model = DataSubjectRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = null;

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getNavigationGroup(): ?string
    {
        return (string) trans('privacy_requests.filament.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return (string) trans('privacy_requests.filament.navigation_label');
    }

    public static function canViewAny(): bool
    {
        return AdminAccess::currentAny([
            AdminAccess::VIEW_AUDIT_LOGS,
            AdminAccess::MANAGE_USERS,
        ]);
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof DataSubjectRequest && static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        // Lifecycle moves are actions with a transition table behind them, not
        // a form that writes whatever is typed into it.
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
        $overdue = DataSubjectRequest::query()->overdue()->count();

        return $overdue > 0 ? (string) $overdue : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return (string) trans('privacy_requests.filament.badge_tooltip');
    }

    /**
     * Eager-load the timeline and the export rows the record screen prints.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['events', 'exports', 'user', 'assignee']);
    }

    public static function form(Form $form): Form
    {
        // Intentionally empty: see canEdit(). Filament still requires the method
        // because the resource is not infolist-only on the create route, which
        // is itself disabled above.
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['user', 'assignee', 'latestExport']))
            ->columns([
                Tables\Columns\TextColumn::make('reference')
                    ->label((string) trans('privacy_requests.filament.fields.reference'))
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('type')
                    ->label((string) trans('privacy_requests.filament.fields.type'))
                    ->formatStateUsing(fn (PrivacyRequestType $state): string => (string) trans($state->labelKey()))
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label((string) trans('privacy_requests.filament.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (PrivacyRequestStatus $state): string => (string) trans('privacy_requests.statuses.'.$state->value))
                    ->color(fn (PrivacyRequestStatus $state): string => match (true) {
                        $state === PrivacyRequestStatus::Completed => 'success',
                        in_array($state, [PrivacyRequestStatus::Rejected, PrivacyRequestStatus::Expired], true) => 'danger',
                        in_array($state, [PrivacyRequestStatus::PendingVerification, PrivacyRequestStatus::NeedsInformation], true) => 'warning',
                        $state === PrivacyRequestStatus::Cancelled => 'gray',
                        default => 'info',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.username')
                    ->label((string) trans('privacy_requests.filament.fields.account'))
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('received_at')
                    ->label((string) trans('privacy_requests.filament.fields.received_at'))
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('due_at')
                    ->label((string) trans('privacy_requests.filament.fields.due_at'))
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable()
                    ->color(fn (DataSubjectRequest $record): string => $record->isOverdue() ? 'danger' : 'gray'),

                Tables\Columns\TextColumn::make('due_in')
                    ->label((string) trans('privacy_requests.filament.fields.days_remaining'))
                    ->state(fn (DataSubjectRequest $record): string => self::dueIn($record)),

                Tables\Columns\TextColumn::make('latestExport.status')
                    ->label((string) trans('privacy_requests.filament.fields.export_status'))
                    ->badge()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('assignee.username')
                    ->label((string) trans('privacy_requests.filament.fields.assigned_to'))
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label((string) trans('privacy_requests.filament.fields.received_at'))
                    ->dateTime()
                    ->since()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label((string) trans('privacy_requests.filament.fields.status'))
                    ->options(collect(PrivacyRequestStatus::cases())
                        ->mapWithKeys(fn (PrivacyRequestStatus $status): array => [
                            $status->value => (string) trans('privacy_requests.statuses.'.$status->value),
                        ])
                        ->all()),

                Tables\Filters\SelectFilter::make('type')
                    ->label((string) trans('privacy_requests.filament.fields.type'))
                    ->options(collect(PrivacyRequestType::cases())
                        ->mapWithKeys(fn (PrivacyRequestType $type): array => [
                            $type->value => (string) trans($type->labelKey()),
                        ])
                        ->all()),

                Tables\Filters\Filter::make('overdue')
                    ->label((string) trans('privacy_requests.filament.filters.overdue'))
                    ->query(fn (Builder $query): Builder => $query->overdue()),

                Tables\Filters\Filter::make('due_soon')
                    ->label((string) trans('privacy_requests.filament.filters.due_soon'))
                    ->query(fn (Builder $query): Builder => $query->dueSoon()),

                Tables\Filters\Filter::make('open')
                    ->label((string) trans('privacy_requests.filament.filters.open'))
                    ->query(fn (Builder $query): Builder => $query->open()),
            ])
            ->actions(DataSubjectRequestActions::forTable())
            ->bulkActions([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make((string) trans('privacy_requests.filament.sections.request'))
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('reference')
                        ->label((string) trans('privacy_requests.filament.fields.reference'))
                        ->copyable(),
                    Infolists\Components\TextEntry::make('type')
                        ->label((string) trans('privacy_requests.filament.fields.type'))
                        ->formatStateUsing(fn (PrivacyRequestType $state): string => (string) trans($state->labelKey())),
                    Infolists\Components\TextEntry::make('status')
                        ->label((string) trans('privacy_requests.filament.fields.status'))
                        ->badge()
                        ->formatStateUsing(fn (PrivacyRequestStatus $state): string => (string) trans('privacy_requests.statuses.'.$state->value)),
                    Infolists\Components\TextEntry::make('locale')
                        ->label((string) trans('privacy_requests.filament.fields.locale')),
                    Infolists\Components\TextEntry::make('received_at')
                        ->label((string) trans('privacy_requests.filament.fields.received_at'))
                        ->dateTime()
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('due_at')
                        ->label((string) trans('privacy_requests.filament.fields.due_at'))
                        ->dateTime()
                        ->placeholder('—')
                        ->color(fn (DataSubjectRequest $record): string => $record->isOverdue() ? 'danger' : 'gray'),
                    Infolists\Components\TextEntry::make('verified_at')
                        ->label((string) trans('privacy_requests.filament.fields.verified_at'))
                        ->dateTime()
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('completed_at')
                        ->label((string) trans('privacy_requests.filament.fields.completed_at'))
                        ->dateTime()
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('rejected_reason_code')
                        ->label((string) trans('privacy_requests.filament.fields.rejected_reason_code'))
                        ->formatStateUsing(fn (?string $state): string => $state === null
                            ? '—'
                            : (string) trans('privacy_requests.reasons.'.$state))
                        ->placeholder('—'),
                ]),

            Infolists\Components\Section::make((string) trans('privacy_requests.filament.sections.requester'))
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('requester_email')
                        ->label((string) trans('privacy_requests.filament.fields.email'))
                        ->state(fn (DataSubjectRequest $record): string => app(PrivacyRequestService::class)->emailOf($record)),

                    Infolists\Components\TextEntry::make('user.username')
                        ->label((string) trans('privacy_requests.filament.fields.account'))
                        ->placeholder('—'),

                    Infolists\Components\TextEntry::make('details_encrypted')
                        ->label((string) trans('privacy_requests.filament.fields.details'))
                        ->placeholder('—')
                        ->columnSpanFull()
                        ->prose(),

                    Infolists\Components\TextEntry::make('legal_hold_reason')
                        ->label((string) trans('privacy_requests.filament.fields.legal_hold_reason'))
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),

            Infolists\Components\Section::make((string) trans('privacy_requests.filament.sections.exports'))
                ->schema([
                    Infolists\Components\RepeatableEntry::make('exports')
                        ->hiddenLabel()
                        ->columns(5)
                        ->schema([
                            Infolists\Components\TextEntry::make('status')
                                ->label((string) trans('privacy_requests.filament.fields.export_status'))
                                ->badge(),
                            Infolists\Components\TextEntry::make('disk')
                                ->label((string) trans('privacy_requests.filament.fields.export_disk')),
                            Infolists\Components\TextEntry::make('size_bytes')
                                ->label((string) trans('privacy_requests.filament.fields.export_size'))
                                ->formatStateUsing(fn ($state, $record): string => (string) $record->humanSize()),
                            Infolists\Components\TextEntry::make('download_count')
                                ->label((string) trans('privacy_requests.filament.fields.export_downloads')),
                            Infolists\Components\TextEntry::make('expires_at')
                                ->label((string) trans('privacy_requests.filament.fields.export_expires'))
                                ->dateTime(),
                        ]),
                ]),

            Infolists\Components\Section::make((string) trans('privacy_requests.filament.sections.timeline'))
                ->schema([
                    Infolists\Components\RepeatableEntry::make('events')
                        ->hiddenLabel()
                        ->columns(3)
                        ->schema([
                            Infolists\Components\TextEntry::make('created_at')
                                ->hiddenLabel()
                                ->dateTime(),
                            Infolists\Components\TextEntry::make('event')
                                ->hiddenLabel()
                                ->formatStateUsing(fn ($state, $record): string => $record->label()),
                            Infolists\Components\TextEntry::make('note_encrypted')
                                ->hiddenLabel()
                                ->placeholder('—'),
                        ]),
                ]),
        ]);
    }

    /**
     * Days left on the clock, or a plain statement that it has run out.
     *
     * A negative number on a compliance screen reads as a data error to most
     * people, so the overdue case says so in words.
     */
    private static function dueIn(DataSubjectRequest $request): string
    {
        if ($request->due_at === null) {
            return '—';
        }

        $days = (int) round(\Illuminate\Support\Carbon::now()->diffInDays($request->due_at, false));

        return $days >= 0
            ? $days.' '.(string) trans('privacy_requests.filament.fields.days_remaining_suffix')
            : (string) trans('privacy_requests.filament.fields.overdue_by', ['days' => abs($days)]);
    }

    /**
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDataSubjectRequests::route('/'),
            'view' => Pages\ViewDataSubjectRequest::route('/{record}'),
        ];
    }
}
