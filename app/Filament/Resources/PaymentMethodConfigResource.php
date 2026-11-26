<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\Currency;
use App\Enums\PaymentMethodStatus;
use App\Filament\Resources\PaymentMethodConfigResource\Pages;
use App\Filament\Resources\PaymentMethodConfigResource\PaymentMethodConfigActions;
use App\Models\PaymentMethodConfig;
use App\Models\PaymentProvider;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminFormat;
use App\Support\Admin\AdminPermission;
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
 * Payment method offers — min/max, fee and lifecycle per provider/method/currency.
 *
 * WHY THIS EXISTS
 *
 * `PaymentProviderRegistry::registerMethod()` and `transitionMethod()` have been able to
 * create an offer and move it through Pending → Enabled → Disabled → Retired since the payment
 * phase, and `resolveOffer()` refuses anything that is not Enabled. Until this screen existed
 * the only way to enable an offer was a tinker session, which meant the seeded rows were the
 * whole offerable menu: a provider could be Active, have a driver, and still carry no traffic
 * because nobody could turn its method on.
 *
 * WHAT IT REFUSES TO DO
 *
 *   1. No edit page. `min_amount`, `max_amount`, `fee_bps`, `method_code` and `currency` are
 *      the facts a payer was shown; the registry treats a re-registration with different
 *      numbers under the same method key as a DUPLICATE rather than an update, and this screen
 *      agrees. Changing the limits means a new offer, and the old row keeps answering "what
 *      did we promise then".
 *   2. No delete. Offer rows are referenced by payment intents; retiring is the permanent move
 *      and it is a lifecycle transition the registry can audit, not a vanished row.
 *   3. No status writes. Every move is `transitionMethod()`, which applies
 *      `PaymentMethodStatus::canTransitionTo()` under a row lock and writes a High-risk audit
 *      line naming the operator.
 *   4. No provider creation from here. A method offer needs a provider row that exists; the
 *      provider registry owns that lifecycle.
 *
 * PERMISSIONS: 'payments.providers.view' to read, 'payments.providers.manage' to create a
 * method or move it.
 */
class PaymentMethodConfigResource extends Resource
{
    protected static ?string $model = PaymentMethodConfig::class;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Method offers';

    protected static ?string $modelLabel = 'method offer';

    protected static ?string $pluralModelLabel = 'method offers';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'method_key';

    public static function canViewAny(): bool
    {
        return AdminAccess::permitsAny([
            AdminPermission::PROVIDERS_VIEW,
            AdminPermission::PROVIDERS_MANAGE,
        ]);
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof PaymentMethodConfig && static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return AdminAccess::permits(AdminPermission::PROVIDERS_MANAGE);
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
        $pending = PaymentMethodConfig::query()
            ->where('status', PaymentMethodStatus::Pending->value)
            ->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('method_key')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('provider'))
            ->columns([
                Tables\Columns\TextColumn::make('provider.code')
                    ->label('Provider')
                    ->searchable()
                    ->sortable()
                    // `provider` is nullable on the row (the FK is nullable), so `?->` stays; `?:`
                    // answers the empty-name case without the redundant `??` on a non-null access.
                    ->description(fn (PaymentMethodConfig $record): string => $record->provider?->name ?: '—'),

                Tables\Columns\TextColumn::make('method_code')
                    ->label('Method')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('currency')
                    ->label('Currency')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('min_amount')
                    ->label('Min')
                    ->formatStateUsing(fn ($state, PaymentMethodConfig $record): string => AdminFormat::money($state, (string) $record->currency))
                    ->sortable(),

                Tables\Columns\TextColumn::make('max_amount')
                    ->label('Max')
                    ->formatStateUsing(fn ($state, PaymentMethodConfig $record): string => AdminFormat::money($state, (string) $record->currency))
                    ->sortable(),

                Tables\Columns\TextColumn::make('fee_bps')
                    ->label('Fee (bps)')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (PaymentMethodStatus $state): string => $state->label())
                    ->color(fn (PaymentMethodStatus $state): string => static::statusColor($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('retired_at')
                    ->label('Retired')
                    ->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('method_key')
                    ->label('Key')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->copyable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(fn (): array => collect(PaymentMethodStatus::cases())
                        ->mapWithKeys(fn (PaymentMethodStatus $status): array => [$status->value => $status->label()])
                        ->all())
                    ->multiple(),

                Tables\Filters\SelectFilter::make('provider')
                    ->label('Provider')
                    ->relationship('provider', 'code')
                    ->searchable(),

                Tables\Filters\SelectFilter::make('currency')
                    ->options(fn (): array => Currency::options()),

                Tables\Filters\Filter::make('unreachable')
                    ->label('Enabled but provider not serving')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('status', PaymentMethodStatus::Enabled->value)
                        ->whereHas('provider', fn (Builder $provider): Builder => $provider->where('status', '!=', 'active')))
                    ->toggle(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\ActionGroup::make(PaymentMethodConfigActions::forTable())
                    ->label('Move')
                    ->icon('heroicon-m-arrow-path')
                    ->visible(fn (): bool => AdminAccess::permits(AdminPermission::PROVIDERS_MANAGE)),
            ])
            ->bulkActions([])
            ->emptyStateHeading('No method offers')
            ->emptyStateDescription('A provider without an offer carries no traffic however Active it looks. Create the first offer from the header action.');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Offer')
                ->schema([
                    Infolists\Components\TextEntry::make('method_key')->label('Method key')->copyable(),
                    Infolists\Components\TextEntry::make('provider.code')->label('Provider'),
                    Infolists\Components\TextEntry::make('provider.status')
                        ->label('Provider status')
                        ->badge()
                        ->helperText('The offer can only be served while the provider is Active — an Enabled method on a Suspended provider is reachable by nobody.'),
                    Infolists\Components\TextEntry::make('method_code')->label('Method'),
                    Infolists\Components\TextEntry::make('currency'),
                    Infolists\Components\TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (PaymentMethodStatus $state): string => $state->label())
                        ->color(fn (PaymentMethodStatus $state): string => static::statusColor($state)),
                ])
                ->columns(3),

            Infolists\Components\Section::make('Commercial terms')
                ->description('The numbers a payer was shown. They are immutable here on purpose: a changed limit under the same method key would be a different promise, not a correction.')
                ->schema([
                    Infolists\Components\TextEntry::make('min_amount')
                        ->label('Minimum')
                        ->formatStateUsing(fn ($state, PaymentMethodConfig $record): string => AdminFormat::money($state, (string) $record->currency)),
                    Infolists\Components\TextEntry::make('max_amount')
                        ->label('Maximum')
                        ->formatStateUsing(fn ($state, PaymentMethodConfig $record): string => AdminFormat::money($state, (string) $record->currency)),
                    Infolists\Components\TextEntry::make('fee_bps')
                        ->label('Fee')
                        ->formatStateUsing(fn ($state): string => sprintf('%d bps (%.2f%%)', (int) $state, ((int) $state) / 100)),
                ])
                ->columns(3),

            Infolists\Components\Section::make('Lifecycle')
                ->schema([
                    Infolists\Components\TextEntry::make('created_at')
                        ->label('Registered')
                        ->state(fn (PaymentMethodConfig $record): string => AdminFormat::marketTime($record->created_at)),
                    Infolists\Components\TextEntry::make('retired_at')
                        ->label('Retired')
                        ->state(fn (PaymentMethodConfig $record): string => AdminFormat::marketTime($record->retired_at)),
                    Infolists\Components\TextEntry::make('status_note')
                        ->label('What this state means')
                        ->state(fn (PaymentMethodConfig $record): string => static::describeStatus($record->status))
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Offer identity')
                ->description('Provider, method code and currency together form the offer key. The same triple cannot exist twice.')
                ->schema([
                    Forms\Components\Select::make('provider_code')
                        ->label('Provider')
                        ->options(fn (): array => PaymentMethodConfigResource::providerOptions())
                        ->required()
                        ->searchable()
                        ->helperText('Retired and disabled providers are still listed: an offer that is configured ahead of a launch is legitimate. The screen never claims it is reachable.'),

                    Forms\Components\TextInput::make('method_code')
                        ->label('Method code')
                        ->required()
                        ->maxLength(32)
                        ->helperText('Lowercase 2–32 characters, e.g. "promptpay" or "bank_transfer".'),

                    Forms\Components\Select::make('currency')
                        ->options(fn (): array => Currency::options())
                        ->required(),
                ])
                ->columns(3),

            Forms\Components\Section::make('Commercial terms')
                ->description('Money is entered as a decimal string and validated as one; it is never turned into a float on the way to the ledger.')
                ->schema([
                    Forms\Components\TextInput::make('min_amount')
                        ->label('Minimum')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->step('0.01'),

                    Forms\Components\TextInput::make('max_amount')
                        ->label('Maximum')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->step('0.01')
                        ->helperText('The registry refuses a maximum below the minimum.'),

                    Forms\Components\TextInput::make('fee_bps')
                        ->label('Fee (basis points)')
                        ->numeric()
                        ->default(0)
                        ->minValue(0)
                        ->maxValue(10_000)
                        ->helperText('0–10000. 250 = 2.5%.'),
                ])
                ->columns(3),

            Forms\Components\Section::make('Initial state')
                ->description('Pending is the safe default: an offer that is configured but not yet offered cannot be bound by a payment intent.')
                ->schema([
                    Forms\Components\Select::make('status')
                        ->label('Status')
                        ->options([
                            PaymentMethodStatus::Pending->value => 'Pending — configured, not offered',
                            PaymentMethodStatus::Enabled->value => 'Enabled — offered immediately',
                        ])
                        ->default(PaymentMethodStatus::Pending->value)
                        ->required()
                        ->helperText('Choose Enabled only when the provider is Active and the driver can actually be invoked; otherwise the offer is reachable on paper only.'),
                ]),
        ]);
    }

    public static function statusColor(PaymentMethodStatus $status): string
    {
        return match ($status) {
            PaymentMethodStatus::Pending => 'warning',
            PaymentMethodStatus::Enabled => 'success',
            PaymentMethodStatus::Disabled => 'gray',
            PaymentMethodStatus::Retired => 'danger',
        };
    }

    public static function describeStatus(PaymentMethodStatus $status): string
    {
        return match ($status) {
            PaymentMethodStatus::Pending => 'Configured but not offered: resolveOffer() will refuse it with "method is pending".',
            PaymentMethodStatus::Enabled => 'Offered: resolveOffer() returns it, subject to the provider still being Active.',
            PaymentMethodStatus::Disabled => 'Off the menu: existing payment records still resolve, new intents do not bind it.',
            PaymentMethodStatus::Retired => 'Permanently closed: even resolution refuses it, and the enum offers no way back.',
        };
    }

    /**
     * Providers that can actually take an offer. Retired/disabled providers are excluded from
     * the create form so a new offer is not born unreachable.
     *
     * @return array<string, string>
     */
    public static function providerOptions(): array
    {
        return PaymentProvider::query()
            ->orderBy('code')
            ->get(['code', 'name', 'status'])
            ->mapWithKeys(fn (PaymentProvider $provider): array => [
                (string) $provider->code => sprintf('%s — %s (%s)', $provider->code, (string) $provider->name, $provider->status->value),
            ])
            ->all();
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
            'index' => Pages\ListPaymentMethodConfigs::route('/'),
            'create' => Pages\CreatePaymentMethodConfig::route('/create'),
            'view' => Pages\ViewPaymentMethodConfig::route('/{record}'),
        ];
    }
}
