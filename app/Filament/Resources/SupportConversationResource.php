<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\Chat\ChatConversationStatus;
use App\Filament\Resources\SupportConversationResource\Pages;
use App\Models\Chat\ChatConversation;
use App\Support\Admin\AdminAccess;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The support inbox — READ-ONLY, on top of the existing chat stack.
 *
 * WHY READ-ONLY
 * Conversations are worked from the operator chat console (/operator/chat), which
 * already owns claiming, assignment, canned responses, presence, SLA timers and the
 * full chat event trail. A second place that can reply would split that trail in two
 * and make "who told the member what" unanswerable. This screen exists so an
 * administrator can see the queue, the ratings and the closures without opening the
 * live console.
 *
 * WHAT IT ADDS THAT THE CONSOLE DOES NOT SHOW
 * The member rating and the close state, which are the two things a review meeting
 * asks about and neither of which is in the live operator view.
 */
final class SupportConversationResource extends Resource
{
    protected static ?string $model = ChatConversation::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationGroup = 'Support';

    protected static ?int $navigationSort = 20;

    public static function canViewAny(): bool
    {
        return AdminAccess::current(AdminAccess::MANAGE_SYSTEM_SETTINGS);
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
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

    public static function getModelLabel(): string
    {
        return 'Support conversation';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Support conversations';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('public_reference')->label('Reference')->searchable(),
                Tables\Columns\TextColumn::make('user_id')
                    ->label('Member')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(static fn ($state): string => $state instanceof ChatConversationStatus
                        ? $state->label()
                        : (string) $state),
                Tables\Columns\TextColumn::make('message_count')->numeric()->label('Msgs'),
                Tables\Columns\TextColumn::make('rating')->placeholder('not rated')->sortable(),
                Tables\Columns\TextColumn::make('last_message_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('closed_at')->dateTime()->placeholder('—'),
            ])
            ->defaultSort('last_message_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(ChatConversationStatus::cases())
                        ->mapWithKeys(static fn (ChatConversationStatus $s): array => [$s->value => $s->label()])
                        ->all()),
            ]);
    }

    /**
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSupportConversations::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        // Member conversations only: guest chats have no member to review and are
        // handled in the operator console.
        return parent::getEloquentQuery()->whereNotNull('user_id');
    }
}
