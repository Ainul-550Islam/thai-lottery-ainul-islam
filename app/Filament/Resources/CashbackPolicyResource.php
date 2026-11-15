<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\AccountGradeLevel;
use App\Filament\Resources\CashbackPolicyResource\Pages\CreateCashbackPolicy;
use App\Filament\Resources\CashbackPolicyResource\Pages\EditCashbackPolicy;
use App\Filament\Resources\CashbackPolicyResource\Pages\ListCashbackPolicies;
use App\Models\CashbackPolicy;
use App\Models\User;
use App\Support\Admin\AdminAccess;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class CashbackPolicyResource extends Resource
{
    protected static ?string $model = CashbackPolicy::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationGroup = 'Rewards & Loyalty';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && AdminAccess::allowsAny($user, [
            AdminAccess::MANAGE_SYSTEM_SETTINGS,
            AdminAccess::VIEW_FINANCIAL_REPORTS,
        ]);
    }

    public static function canCreate(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && ! AdminAccess::isReadOnly($user)
            && AdminAccess::allows($user, AdminAccess::MANAGE_SYSTEM_SETTINGS);
    }

    public static function canEdit(Model $record): bool
    {
        return self::canCreate();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        $gradeOptions = [];
        foreach (AccountGradeLevel::cases() as $level) {
            $gradeOptions[$level->value] = strtoupper(str_replace('_', ' ', $level->value));
        }

        return $form->schema([
            Forms\Components\Select::make('grade')
                ->options($gradeOptions)
                ->required(),
            Forms\Components\Select::make('period')
                ->options([
                    'daily' => 'Daily',
                    'weekly' => 'Weekly',
                ])
                ->default('daily')
                ->required(),
            Forms\Components\TextInput::make('rate_bps')
                ->label('Rate (basis points)')
                ->numeric()
                ->minValue(0)
                ->maxValue(10000)
                ->required(),
            Forms\Components\TextInput::make('basis')
                ->default('real_net_loss')
                ->disabled()
                ->dehydrated()
                ->required(),
            Forms\Components\TextInput::make('min_claim')
                ->required()
                ->rule('regex:/^\d+(\.\d{1,2})?$/'),
            Forms\Components\TextInput::make('max_per_period')
                ->required()
                ->rule('regex:/^\d+(\.\d{1,2})?$/'),
            Forms\Components\TextInput::make('expiry_days')
                ->numeric()
                ->minValue(1)
                ->maxValue(365)
                ->required(),
            Forms\Components\Toggle::make('active')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('grade')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('period')->sortable(),
                Tables\Columns\TextColumn::make('rate_bps')->label('Rate (bps)')->sortable(),
                Tables\Columns\TextColumn::make('min_claim')->sortable(),
                Tables\Columns\TextColumn::make('max_per_period')->sortable(),
                Tables\Columns\TextColumn::make('expiry_days')->sortable(),
                Tables\Columns\IconColumn::make('active')->boolean()->sortable(),
                Tables\Columns\TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListCashbackPolicies::route('/'),
            'create' => CreateCashbackPolicy::route('/create'),
            'edit' => EditCashbackPolicy::route('/{record}/edit'),
        ];
    }
}
