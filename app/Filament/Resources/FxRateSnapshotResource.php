<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\FxRateSnapshotResource\Pages;
use App\Models\FxRateSnapshot;
use App\Support\Admin\AdminAccess;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Admin screen for DISPLAY exchange rates.
 *
 * WHY THIS IS A MANUAL-ENTRY SCREEN AND NOT AN IMPORTER
 * config('player_experience.fx.driver') is `null` by default: no outbound call, no
 * rate. A deployment that wants a display FX figure turns the driver to `manual` and
 * a human with a contracted rate sheet enters it here, with a source reference. The
 * rate is DISPLAY ONLY and nothing in the money path reads this table — the
 * description on the screen says so, because an operator who believes this feeds
 * conversions would use it as if it did.
 *
 * WHY NOTHING CAN BE EDITED AFTER THE FACT
 * A rate that can be silently rewritten cannot be used to explain what a player was
 * shown. Rows are append-only from this screen: create a corrected row instead.
 */
final class FxRateSnapshotResource extends Resource
{
    protected static ?string $model = FxRateSnapshot::class;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 90;

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
        return AdminAccess::current(AdminAccess::MANAGE_SYSTEM_SETTINGS);
    }

    /** Rates are history: a wrong one is superseded, never rewritten or deleted. */
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
        return 'Display FX rate';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Display FX rates';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Pair')
                ->description('DISPLAY ONLY. Conversions keep using the audited wallet-conversion quote service; this rate never moves money.')
                ->schema([
                    Forms\Components\TextInput::make('base')
                        ->required()
                        ->maxLength(3)
                        ->default('THB')
                        ->helperText('ISO 4217 code, three letters.'),

                    Forms\Components\TextInput::make('quote')
                        ->required()
                        ->maxLength(3)
                        ->default('USD'),

                    Forms\Components\TextInput::make('rate')
                        ->required()
                        ->rule('regex:/^\d+(\.\d{1,12})?$/')
                        ->helperText('Decimal string, up to 12 places. 1 unit of base = this many units of quote.'),
                ])->columns(3),

            Forms\Components\Section::make('Provenance')
                ->schema([
                    Forms\Components\TextInput::make('source')
                        ->required()
                        ->maxLength(40)
                        ->default(fn (): string => (string) config('player_experience.fx.manual_source', 'manual_admin')),

                    Forms\Components\DateTimePicker::make('captured_at')
                        ->required()
                        ->default(fn () => now())
                        ->helperText('The moment the rate was true. A stale rate is not shown to players: FxDisplayService returns null past the configured maximum age.'),

                    Forms\Components\DateTimePicker::make('expires_at')
                        ->helperText('Optional hard stop. After this moment the row is not used even if it is newer than the maximum age.'),

                    Forms\Components\TextInput::make('reference')
                        ->maxLength(96)
                        ->helperText('Contract, rate sheet or ticket number this number came from.'),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('base')->sortable(),
                Tables\Columns\TextColumn::make('quote')->sortable(),
                Tables\Columns\TextColumn::make('rate'),
                Tables\Columns\TextColumn::make('source')->badge(),
                Tables\Columns\TextColumn::make('captured_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('expires_at')->dateTime()->placeholder('—'),
            ])
            ->defaultSort('captured_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('source')
                    ->options(fn (): array => FxRateSnapshot::query()
                        ->distinct()
                        ->pluck('source', 'source')
                        ->all()),
            ]);
    }

    /**
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFxRateSnapshots::route('/'),
            'create' => Pages\CreateFxRateSnapshot::route('/create'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        // Newest first: an operator looks at this table to answer "what rate are we
        // showing right now", which is the most recent row.
        return parent::getEloquentQuery()->orderByDesc('captured_at');
    }
}
