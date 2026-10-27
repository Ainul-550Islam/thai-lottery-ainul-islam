<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\PromoCouponResource\Pages;
use App\Models\PromoCoupon;
use App\Support\Admin\AdminAccess;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Filament admin resource for promotional coupons (Audit §D6.1).
 *
 * `redemptions_count` is read-only and owned exclusively by
 * `App\Services\Promotions\CouponRedemptionService` under row lock.
 */
final class PromoCouponResource extends Resource
{
    protected static ?string $model = PromoCoupon::class;

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $navigationGroup = 'Marketing & Content';

    protected static ?string $navigationLabel = 'Promo Coupons';

    /*
     * AUTHORISATION.
     *
     * A coupon changes what a member is charged, so creating or editing one is a
     * financial-settings action. Viewing is gated on the same permission as the
     * rest of the marketing/promotional console; nothing here inherits the
     * framework's permissive default.
     */
    public static function canViewAny(): bool
    {
        return AdminAccess::current(AdminAccess::MANAGE_SYSTEM_SETTINGS);
    }

    public static function canView(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return AdminAccess::current(AdminAccess::MANAGE_SYSTEM_SETTINGS);
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return AdminAccess::current(AdminAccess::MANAGE_SYSTEM_SETTINGS);
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        // Redeemed coupons are financial evidence; they are deactivated, never removed.
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Coupon Identity')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('code')
                        ->required()
                        ->maxLength(32)
                        ->alphaDash()
                        ->dehydrateStateUsing(static fn (?string $state): string => strtoupper(trim((string) $state)))
                        ->unique(ignoreRecord: true),
                    Forms\Components\TextInput::make('title')
                        ->required()
                        ->maxLength(160),
                    Forms\Components\Textarea::make('description')
                        ->maxLength(500)
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Grant & Risk Controls')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('discount_type')
                        ->options([
                            'fixed' => 'Fixed Bonus Grant',
                            'percentage_match' => 'Deposit Percentage Match',
                        ])
                        ->default('fixed')
                        ->required(),
                    Forms\Components\TextInput::make('amount')
                        ->numeric()
                        ->minValue(0.01)
                        ->maxValue((float) config('conversion_tooling.coupons.max_grant_ceiling', 5000))
                        ->required(),
                    Forms\Components\TextInput::make('max_bonus_amount')
                        ->numeric()
                        ->minValue(0.01)
                        ->nullable(),
                    Forms\Components\TextInput::make('currency')
                        ->default('THB')
                        ->length(3)
                        ->required(),
                    Forms\Components\Select::make('target_wallet_type')
                        ->options([
                            'bonus' => 'Bonus Wallet (Recommended)',
                            'primary' => 'Primary Wallet',
                        ])
                        ->default('bonus')
                        ->required(),
                    Forms\Components\TextInput::make('min_deposit_total')
                        ->numeric()
                        ->default('0.00')
                        ->minValue(0),
                    Forms\Components\TextInput::make('max_redemptions')
                        ->integer()
                        ->minValue(1)
                        ->nullable(),
                    Forms\Components\TextInput::make('per_user_limit')
                        ->integer()
                        ->default(1)
                        ->minValue(1)
                        ->required(),
                    Forms\Components\Toggle::make('first_deposit_only')
                        ->default(false),
                    Forms\Components\Toggle::make('is_active')
                        ->default(true),
                    Forms\Components\DateTimePicker::make('starts_at')
                        ->nullable(),
                    Forms\Components\DateTimePicker::make('expires_at')
                        ->nullable(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('title')->searchable()->limit(40),
                Tables\Columns\TextColumn::make('discount_type')->badge(),
                Tables\Columns\TextColumn::make('amount')->money('THB')->sortable(),
                Tables\Columns\TextColumn::make('redemptions_count')
                    ->label('Redeemed')
                    ->sortable(),
                Tables\Columns\TextColumn::make('max_redemptions')
                    ->label('Cap')
                    ->placeholder('Unlimited'),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
                Tables\Columns\TextColumn::make('expires_at')->dateTime()->sortable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPromoCoupons::route('/'),
            'create' => Pages\CreatePromoCoupon::route('/create'),
            'edit' => Pages\EditPromoCoupon::route('/{record}/edit'),
        ];
    }
}
