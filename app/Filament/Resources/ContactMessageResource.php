<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ContactMessageResource\ContactMessageActions;
use App\Models\ContactMessageEvent;
use App\Filament\Resources\ContactMessageResource\Pages;
use App\Models\ContactMessage;
use App\Support\Admin\AdminAccess;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The support inbox.
 *
 * =========================================================================
 * WHAT IT IS
 * =========================================================================
 * Every message a visitor sent through the public contact form, in the order a
 * support lead needs to see them: unworked first, then the rest. The record
 * screen shows the message, who sent it, whether a provider accepted the
 * confirmation copy, and the internal timeline of what staff did about it.
 *
 * =========================================================================
 * WHY THERE IS NO DELETE, ANYWHERE
 * =========================================================================
 * A contact message is the platform's record of what a stranger said and what
 * was done about it. Deleting it would let an inconvenient complaint vanish,
 * and the honest closed states already exist: SPAM (this is not a real support
 * request) and ARCHIVED (nothing left to do). Both are reversible, both are in
 * the timeline with an author, and neither destroys the row.
 *
 * =========================================================================
 * WHAT NEVER LEAVES THIS SCREEN
 * =========================================================================
 * Internal notes are stored encrypted and shown only here; the public status
 * lookup reads contact_messages.status and nothing else. A reply is queued
 * through SupportTicketService, which refuses to queue anything when no reply
 * mailbox is configured rather than reporting a success that did not happen.
 */
final class ContactMessageResource extends Resource
{
    protected static ?string $model = ContactMessage::class;

    protected static ?string $navigationIcon = 'heroicon-o-inbox';

    protected static ?int $navigationSort = 41;

    protected static ?string $recordTitleAttribute = 'public_reference';

    public static function getNavigationGroup(): ?string
    {
        return (string) trans('support_tickets.filament.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return (string) trans('support_tickets.filament.navigation_label');
    }

    public static function canViewAny(): bool
    {
        return AdminAccess::currentAny([AdminAccess::MANAGE_USERS]);
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof ContactMessage && static::canViewAny();
    }

    public static function canCreate(): bool
    {
        // A ticket exists because a visitor sent one.
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        // The visitor's words are not editable. Status, notes and replies are
        // actions, so each of them carries an author and a timeline entry.
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
        $waiting = ContactMessage::query()
            ->where('status', ContactMessage::STATUS_RECEIVED)
            ->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return (string) trans('support_tickets.filament.badge_tooltip');
    }

    /**
     * Eager-load the two relations the record screen prints.
     *
     * Outside production this application forbids lazy loading, and a support
     * timeline is exactly the place where a missing eager load would turn one
     * page load into N queries.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('deliveries');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('deliveries'))
            ->columns([
                Tables\Columns\TextColumn::make('public_reference')
                    ->label((string) trans('support_tickets.filament.fields.reference'))
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('name')
                    ->label((string) trans('support_tickets.filament.fields.name'))
                    ->searchable()
                    ->limit(30),

                Tables\Columns\TextColumn::make('email')
                    ->label((string) trans('support_tickets.filament.fields.email'))
                    ->searchable()
                    ->limit(40),

                Tables\Columns\TextColumn::make('subject')
                    ->label((string) trans('support_tickets.filament.fields.subject'))
                    ->searchable()
                    ->limit(50),

                Tables\Columns\TextColumn::make('status')
                    ->label((string) trans('support_tickets.filament.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => (string) trans(
                        config('support_tickets.status_label_keys.'.$state, 'support_tickets.status.unknown'),
                    ))
                    ->color(fn (string $state): string => match ($state) {
                        ContactMessage::STATUS_RECEIVED => 'warning',
                        ContactMessage::STATUS_IN_REVIEW => 'info',
                        ContactMessage::STATUS_RESOLVED => 'success',
                        ContactMessage::STATUS_SPAM => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('delivery_state')
                    ->label((string) trans('support_tickets.filament.fields.delivery_state'))
                    ->badge()
                    ->color(fn (string $state): string => $state === ContactMessage::DELIVERY_SENT ? 'success' : 'gray')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('locale')
                    ->label((string) trans('support_tickets.filament.fields.locale'))
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label((string) trans('support_tickets.filament.fields.created_at'))
                    ->dateTime()
                    ->since()
                    ->sortable(),

                Tables\Columns\TextColumn::make('resolved_at')
                    ->label((string) trans('support_tickets.filament.fields.resolved_at'))
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label((string) trans('support_tickets.filament.fields.status'))
                    ->options(collect([ContactMessage::STATUS_RECEIVED, ContactMessage::STATUS_IN_REVIEW, ContactMessage::STATUS_RESOLVED, ContactMessage::STATUS_SPAM, ContactMessage::STATUS_ARCHIVED])
                        ->mapWithKeys(fn (string $status): array => [
                            $status => (string) trans(config('support_tickets.status_label_keys.'.$status, 'support_tickets.status.unknown')),
                        ])
                        ->all()),

                Tables\Filters\SelectFilter::make('delivery_state')
                    ->label((string) trans('support_tickets.filament.fields.delivery_state'))
                    ->options(collect([
                        ContactMessage::DELIVERY_SENT,
                        ContactMessage::DELIVERY_PENDING,
                        ContactMessage::DELIVERY_NOT_CONFIGURED,
                        ContactMessage::DELIVERY_FAILED,
                        ContactMessage::DELIVERY_NOT_ATTEMPTED,
                    ])
                        ->mapWithKeys(fn (string $state): array => [
                            $state => (string) trans('support_tickets.delivery.'.$state),
                        ])
                        ->all()),

                Tables\Filters\SelectFilter::make('locale')
                    ->label((string) trans('support_tickets.filament.fields.locale'))
                    ->options(collect(['en', 'th', 'bn'])
                        ->mapWithKeys(fn (string $locale): array => [$locale => strtoupper($locale)])
                        ->all()),

                // The date filter is a RANGE, not two boxes: a support desk asks
                // "what came in since Monday", and that is one question.
                Tables\Filters\Filter::make('received_between')
                    ->label((string) trans('support_tickets.filament.filters.received_between'))
                    ->form([
                        Forms\Components\DatePicker::make('from')
                            ->label((string) trans('support_tickets.filament.filters.from')),
                        Forms\Components\DatePicker::make('until')
                            ->label((string) trans('support_tickets.filament.filters.until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            filled($data['from'] ?? null),
                            fn (Builder $inner): Builder => $inner->whereDate('created_at', '>=', (string) $data['from']),
                        )
                        ->when(
                            filled($data['until'] ?? null),
                            fn (Builder $inner): Builder => $inner->whereDate('created_at', '<=', (string) $data['until']),
                        )),
            ])
            ->actions(ContactMessageActions::forTable())
            ->bulkActions([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make((string) trans('support_tickets.filament.sections.message'))
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('public_reference')
                        ->label((string) trans('support_tickets.filament.fields.reference'))
                        ->copyable(),
                    Infolists\Components\TextEntry::make('status')
                        ->label((string) trans('support_tickets.filament.fields.status'))
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => (string) trans(
                            config('support_tickets.status_label_keys.'.$state, 'support_tickets.status.unknown'),
                        )),
                    Infolists\Components\TextEntry::make('locale')
                        ->label((string) trans('support_tickets.filament.fields.locale')),
                    Infolists\Components\TextEntry::make('subject')
                        ->label((string) trans('support_tickets.filament.fields.subject'))
                        ->columnSpanFull(),
                    Infolists\Components\TextEntry::make('message')
                        ->label((string) trans('support_tickets.filament.fields.message'))
                        ->columnSpanFull()
                        ->prose(),
                ]),

            Infolists\Components\Section::make((string) trans('support_tickets.filament.sections.sender'))
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('name')
                        ->label((string) trans('support_tickets.filament.fields.name')),
                    Infolists\Components\TextEntry::make('email')
                        ->label((string) trans('support_tickets.filament.fields.email')),
                    Infolists\Components\TextEntry::make('created_at')
                        ->label((string) trans('support_tickets.filament.fields.created_at'))
                        ->dateTime(),
                ]),

            Infolists\Components\Section::make((string) trans('support_tickets.filament.sections.delivery'))
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('delivery_state')
                        ->label((string) trans('support_tickets.filament.fields.delivery_state'))
                        ->badge(),
                    Infolists\Components\TextEntry::make('deliveries.state')
                        ->label((string) trans('support_tickets.filament.fields.delivery_state'))
                        ->badge()
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('resolved_at')
                        ->label((string) trans('support_tickets.filament.fields.resolved_at'))
                        ->dateTime()
                        ->placeholder('—'),
                ]),

            Infolists\Components\Section::make((string) trans('support_tickets.filament.sections.timeline'))
                ->schema([
                    Infolists\Components\RepeatableEntry::make('timeline')
                        ->hiddenLabel()
                        ->columns(3)
                        // Read through an explicit query rather than a model
                        // relation: App\Models\ContactMessage belongs to another
                        // lane in this build and is not this one's to extend.
                        ->state(fn (ContactMessage $record): array => ContactMessageEvent::query()
                            ->with('actor')
                            ->where('contact_message_id', (int) $record->getKey())
                            ->orderBy('id')
                            ->get()
                            ->map(static fn (ContactMessageEvent $event): array => [
                                'occurred_at' => $event->created_at?->toDateTimeString(),
                                'label' => (string) trans('support_tickets.events.'.$event->type),
                                'actor' => (string) ($event->actor?->username ?? ''),
                                'detail' => (string) ($event->body_encrypted ?? ''),
                            ])
                            ->all())
                        ->schema([
                            Infolists\Components\TextEntry::make('occurred_at')
                                ->hiddenLabel(),
                            Infolists\Components\TextEntry::make('label')
                                ->hiddenLabel(),
                            Infolists\Components\TextEntry::make('actor')
                                ->hiddenLabel()
                                ->placeholder('—'),
                            Infolists\Components\TextEntry::make('detail')
                                ->hiddenLabel()
                                ->placeholder('—')
                                ->columnSpanFull(),
                        ]),
                ]),
        ]);
    }

    /**
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListContactMessages::route('/'),
            'view' => Pages\ViewContactMessage::route('/{record}'),
        ];
    }
}
