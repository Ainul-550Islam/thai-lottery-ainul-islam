<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\PointsMultiplierCampaignResource\Pages;
use App\Models\PointsMultiplierCampaign;
use App\Support\Admin\AdminAccess;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Admin screen for points-multiplier campaigns.
 *
 * BASIS POINTS, WITH THE MULTIPLIER SHOWN NEXT TO THE NUMBER
 * An operator thinks in "1.5x"; the system stores 15000 bps so the arithmetic stays
 * integer. The field accepts bps and the helper text translates, so nobody types
 * "1.5" into a bps column and ships a campaign worth 0.00015x.
 *
 * OVERLAP IS A WARNING, NOT A REFUSAL
 * Two overlapping campaigns are legal under the highest-only rule, and there are real
 * reasons for them (one all-games campaign plus one game-specific boost). A hard
 * block would force an operator to delete a working campaign to run a weekend
 * promotion. The list shows the overlap so it is a decision, not an accident.
 *
 * THE CEILING COMES FROM CONFIG
 * config('player_experience.loyalty.max_multiplier_bps') is enforced here and again
 * in PointsMultiplierService, because a form is a guard only while everybody uses it.
 */
final class PointsMultiplierCampaignResource extends Resource
{
    protected static ?string $model = PointsMultiplierCampaign::class;

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationGroup = 'Promotions';

    protected static ?int $navigationSort = 10;

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

    public static function canEdit(Model $record): bool
    {
        return AdminAccess::current(AdminAccess::MANAGE_SYSTEM_SETTINGS);
    }

    /**
     * A campaign that already paid points is history. It is deactivated, never
     * deleted, because the multiplier it applied is recorded on points ledger rows
     * and a deleted campaign would make those rows unexplainable.
     */
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
        return 'Points campaign';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Points campaigns';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Campaign')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(120),

                    Forms\Components\TextInput::make('slug')
                        ->required()
                        ->maxLength(120)
                        ->unique(ignoreRecord: true)
                        ->helperText('Stable identifier recorded on the points ledger row. Do not change it after launch.'),

                    Forms\Components\TextInput::make('multiplier_bps')
                        ->label('Multiplier (basis points)')
                        ->required()
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue((int) config('player_experience.loyalty.max_multiplier_bps', 50000))
                        ->default(10000)
                        ->helperText('10000 = 1.0x (no change), 15000 = 1.5x, 20000 = 2.0x. Integer only: the points ledger is integer-valued.'),

                    Forms\Components\TextInput::make('game_scope')
                        ->maxLength(40)
                        ->helperText('A market key such as 3d_direct, or empty for every game.'),

                    Forms\Components\TextInput::make('tier_scope')
                        ->maxLength(32)
                        ->helperText('An account tier key, or empty for every tier.'),

                    Forms\Components\Toggle::make('active')
                        ->default(true)
                        ->helperText('Off means the campaign pays nothing, whatever its window says.'),
                ])->columns(2),

            Forms\Components\Section::make('Window')
                ->description('Both bounds are INCLUSIVE. A campaign that ends at 23:59:59 still pays at 23:59:59.')
                ->schema([
                    Forms\Components\DateTimePicker::make('starts_at')
                        ->required()
                        ->default(fn () => now()),

                    Forms\Components\DateTimePicker::make('ends_at')
                        ->helperText('Empty means open-ended.'),
                ])->columns(2),

            Forms\Components\Section::make('Notes')
                ->collapsed()
                ->schema([
                    Forms\Components\Textarea::make('description')
                        ->rows(3)
                        ->maxLength(500),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->limit(40),
                Tables\Columns\TextColumn::make('multiplier_bps')
                    ->label('Multiplier')
                    ->formatStateUsing(static fn ($state): string => bcdiv((string) $state, '10000', 4).'x')
                    ->sortable(),
                Tables\Columns\TextColumn::make('game_scope')->placeholder('all games'),
                Tables\Columns\TextColumn::make('starts_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('ends_at')->dateTime()->placeholder('open-ended'),
                Tables\Columns\IconColumn::make('active')->boolean(),
            ])
            ->defaultSort('starts_at', 'desc')
            ->filters([
                Tables\Filters\TernaryFilter::make('active'),
            ]);
    }

    /**
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPointsMultiplierCampaigns::route('/'),
            'create' => Pages\CreatePointsMultiplierCampaign::route('/create'),
            'edit' => Pages\EditPointsMultiplierCampaign::route('/{record}/edit'),
        ];
    }
}
