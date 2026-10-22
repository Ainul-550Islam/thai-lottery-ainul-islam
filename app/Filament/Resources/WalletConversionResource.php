<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\WalletConversionResource\Pages\ListWalletConversions;
use App\Models\WalletConversion;
use App\Support\Admin\AdminAccess;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Read-only financial evidence; operator reversals use the authorised engine. */
final class WalletConversionResource extends Resource
{
    protected static ?string $model = WalletConversion::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationGroup = 'Finance';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user !== null && AdminAccess::allows($user, AdminAccess::VIEW_TRANSACTION_HISTORY);
    }

    public static function canView(Model $record): bool
    {
        return self::canViewAny();
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

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('reference')->searchable()->copyable(),
            Tables\Columns\TextColumn::make('user_id')->sortable(),
            Tables\Columns\TextColumn::make('direction')->formatStateUsing(fn ($state) => $state->label()),
            Tables\Columns\TextColumn::make('status')->formatStateUsing(fn ($state) => $state->label()),
            Tables\Columns\TextColumn::make('amount_in'),
            Tables\Columns\TextColumn::make('fee_amount'),
            Tables\Columns\TextColumn::make('amount_out'),
            Tables\Columns\TextColumn::make('currency'),
            Tables\Columns\TextColumn::make('completed_at')->dateTime()->sortable(),
        ])->defaultSort('id', 'desc')->bulkActions([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('reference'), TextEntry::make('user_id'),
            TextEntry::make('direction')->formatStateUsing(fn ($state) => $state->label()),
            TextEntry::make('status')->formatStateUsing(fn ($state) => $state->label()),
            TextEntry::make('amount_in'), TextEntry::make('fee_amount'), TextEntry::make('amount_out'),
            TextEntry::make('currency'), TextEntry::make('fee_rule_version'),
            TextEntry::make('completed_at')->dateTime(), TextEntry::make('reversal_reason'),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListWalletConversions::route('/')];
    }
}
