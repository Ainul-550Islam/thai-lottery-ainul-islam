<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\RewardType;
use App\Filament\Resources\RewardCatalogItemResource\Pages\CreateRewardCatalogItem;
use App\Filament\Resources\RewardCatalogItemResource\Pages\EditRewardCatalogItem;
use App\Filament\Resources\RewardCatalogItemResource\Pages\ListRewardCatalogItems;
use App\Models\RewardCatalogItem;
use App\Models\User;
use App\Support\Admin\AdminAccess;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class RewardCatalogItemResource extends Resource
{
    protected static ?string $model = RewardCatalogItem::class;

    protected static ?string $navigationIcon = 'heroicon-o-gift';

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
        $typeOptions = [];
        foreach (RewardType::cases() as $type) {
            $typeOptions[$type->value] = $type->label();
        }

        return $form->schema([
            Forms\Components\TextInput::make('code')
                ->required()
                ->maxLength(64)
                ->unique(ignoreRecord: true),
            Forms\Components\TextInput::make('name')
                ->required()
                ->maxLength(160),
            Forms\Components\Textarea::make('description')
                ->columnSpanFull(),
            Forms\Components\Select::make('type')
                ->options($typeOptions)
                ->required(),
            Forms\Components\TextInput::make('points_cost')
                ->numeric()
                ->minValue(1)
                ->required(),
            Forms\Components\KeyValue::make('payload')
                ->required()
                ->columnSpanFull(),
            Forms\Components\TextInput::make('stock')
                ->numeric()
                ->minValue(0)
                ->nullable(),
            Forms\Components\TextInput::make('per_member_limit')
                ->numeric()
                ->minValue(1)
                ->nullable(),
            Forms\Components\DateTimePicker::make('starts_at')->nullable(),
            Forms\Components\DateTimePicker::make('ends_at')->nullable(),
            Forms\Components\Toggle::make('active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('type')
                    ->formatStateUsing(static fn ($state): string => $state instanceof RewardType ? $state->label() : (string) $state)
                    ->sortable(),
                Tables\Columns\TextColumn::make('points_cost')->sortable(),
                Tables\Columns\TextColumn::make('stock')->placeholder('Unlimited'),
                Tables\Columns\TextColumn::make('per_member_limit')->placeholder('Unlimited'),
                Tables\Columns\IconColumn::make('active')->boolean()->sortable(),
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
            'index' => ListRewardCatalogItems::route('/'),
            'create' => CreateRewardCatalogItem::route('/create'),
            'edit' => EditRewardCatalogItem::route('/{record}/edit'),
        ];
    }
}
