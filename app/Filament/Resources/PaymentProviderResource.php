<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\PaymentProviderStatus;
use App\Filament\Resources\PaymentProviderResource\Pages;
use App\Models\PaymentMethodConfig;
use App\Models\PaymentProvider;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\PaymentProviderRegistry;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminFormat;
use App\Support\Admin\AdminPermission;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Payment provider administration.
 *
 * WHY THIS SCREEN WAS MISSING AND WHY IT MATTERS
 *
 * The provider registry (App\Services\Payment\PaymentProviderRegistry) has been able to
 * register providers and move them through their lifecycle since the payment phase, with
 * an audit line on every transition. But the only way to reach it was a tinker session,
 * so in practice a provider stayed in whatever state the seeder left it: an operator who
 * needed to pull a gateway out of traffic at 3am could not.
 *
 * THE THREE THINGS THIS SCREEN REFUSES TO DO
 *
 *   1. It does not write PaymentProvider.status itself. Every transition goes through
 *      PaymentProviderRegistry::transitionProvider(), which applies the enum's own
 *      transition map and writes the audit row. A disabled provider cannot be revived
 *      from here because the enum says it cannot be revived at all.
 *   2. It does not show credentials. The registry's contract is that secrets live in
 *      env/config and the table holds only `non_secrets`; this screen still masks any
 *      value whose key smells like a secret, because a "non-secret" column is a promise
 *      about intent, not a guarantee about content.
 *   3. It does not invent a provider. There is no create page: a provider row without a
 *      driver class behind it would look configured while being unable to move money.
 *
 * STATUS IS NOT HEALTH
 *
 * `status` says what the house decided; `PaymentGatewayManager::availability()` says
 * whether the driver can actually be invoked right now (credentials present, config
 * resolved). The view shows both, because "Active" beside a gateway that cannot
 * authenticate is the single most misleading state this panel can display.
 */
class PaymentProviderResource extends Resource
{
    protected static ?string $model = PaymentProvider::class;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Payment providers';

    protected static ?string $modelLabel = 'payment provider';

    protected static ?string $pluralModelLabel = 'payment providers';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'code';

    /** @var list<string> */
    private const LIFECYCLE_VERBS = ['activate', 'suspend', 'disable'];

    public static function canViewAny(): bool
    {
        return AdminAccess::permits(AdminPermission::PROVIDERS_VIEW);
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof PaymentProvider && static::canViewAny();
    }

    public static function canCreate(): bool
    {
        // Providers are provisioned with a driver class, not typed into a form.
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        // Lifecycle changes are actions, not field edits; see the class comment.
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
        return $table
            ->defaultSort('code')
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Code')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Provider')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('driver')
                    ->label('Driver')
                    ->badge()
                    ->color('gray')
                    ->searchable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (PaymentProviderStatus $state): string => $state->label())
                    ->color(fn (PaymentProviderStatus $state): string => static::statusColor($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('currencies')
                    ->label('Currencies')
                    ->state(fn (PaymentProvider $record): string => implode(', ', array_map(
                        static fn ($currency): string => is_object($currency) && property_exists($currency, 'value')
                            ? (string) $currency->value
                            : (string) $currency,
                        (array) ($record->supported_currencies ?? []),
                    )) ?: '—')
                    ->wrap(),

                Tables\Columns\TextColumn::make('methods_count')
                    ->label('Methods')
                    ->counts('methods')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray'),

                Tables\Columns\TextColumn::make('status_reason')
                    ->label('Reason')
                    ->placeholder('—')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Changed')
                    ->since()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(fn (): array => collect(PaymentProviderStatus::cases())
                        ->mapWithKeys(fn (PaymentProviderStatus $status): array => [$status->value => $status->label()])
                        ->all()),

                Tables\Filters\SelectFilter::make('driver')
                    ->options(fn (): array => PaymentProvider::query()
                        ->distinct()
                        ->orderBy('driver')
                        ->pluck('driver', 'driver')
                        ->all()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                Tables\Actions\ActionGroup::make(static::tableLifecycleActions())
                    ->label('Lifecycle')
                    ->icon('heroicon-m-arrow-path')
                    ->button()
                    ->outlined()
                    ->visible(fn (): bool => AdminAccess::permits(AdminPermission::PROVIDERS_MANAGE)),
            ])
            ->bulkActions([])
            ->emptyStateHeading('No providers registered')
            ->emptyStateDescription('Providers are registered by the payment provisioning lane; none exist yet.');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Identity')
                ->schema([
                    Infolists\Components\TextEntry::make('code')->label('Code')->copyable(),
                    Infolists\Components\TextEntry::make('name')->label('Provider'),
                    Infolists\Components\TextEntry::make('driver')->label('Driver'),
                    Infolists\Components\TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (PaymentProviderStatus $state): string => $state->label())
                        ->color(fn (PaymentProviderStatus $state): string => static::statusColor($state)),
                ])
                ->columns(4),

            Infolists\Components\Section::make('Lifecycle')
                ->description('Status moves only through the transition map in PaymentProviderStatus. Disabling is terminal.')
                ->schema([
                    Infolists\Components\TextEntry::make('status_reason')
                        ->label('Last reason')
                        ->placeholder('—')
                        ->columnSpan(2),
                    Infolists\Components\TextEntry::make('suspended_at')
                        ->label('Suspended at')
                        ->state(fn (PaymentProvider $record): string => AdminFormat::marketTime($record->suspended_at)),
                    Infolists\Components\TextEntry::make('disabled_at')
                        ->label('Disabled at')
                        ->state(fn (PaymentProvider $record): string => AdminFormat::marketTime($record->disabled_at)),
                ])
                ->columns(4),

            Infolists\Components\Section::make('Capabilities')
                ->description('What the registry advertises for this provider. Currencies come from the registration; fee and limit policy lives per method below.')
                ->schema([
                    Infolists\Components\TextEntry::make('non_secret_keys')
                        ->label('Non-secret configuration keys')
                        ->state(fn (PaymentProvider $record): string => static::describeNonSecrets($record))
                        ->columnSpanFull(),
                ]),

            Infolists\Components\Section::make('Method offers')
                ->description('One row per method/currency the registry will resolve. A provider with no offers carries no traffic however Active it looks.')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('methods')
                        ->hiddenLabel()
                        ->schema([
                            Infolists\Components\TextEntry::make('method_code')->label('Method'),
                            Infolists\Components\TextEntry::make('currency')->label('Currency'),
                            Infolists\Components\TextEntry::make('min_amount')
                                ->label('Min')
                                ->formatStateUsing(fn ($state, PaymentMethodConfig $record): string => AdminFormat::money($state, (string) $record->currency)),
                            Infolists\Components\TextEntry::make('max_amount')
                                ->label('Max')
                                ->formatStateUsing(fn ($state, PaymentMethodConfig $record): string => AdminFormat::money($state, (string) $record->currency)),
                            Infolists\Components\TextEntry::make('fee_bps')->label('Fee (bps)'),
                            Infolists\Components\TextEntry::make('status')->label('Status')->badge(),
                        ])
                        ->columns(6)
                        ->columnSpanFull(),
                ]),

            Infolists\Components\Section::make('Runtime readiness')
                ->description('Reported by PaymentGatewayManager::availability() for the driver this provider names. "Active" is the house decision; readiness is whether the driver can actually be invoked.')
                ->schema([
                    Infolists\Components\TextEntry::make('readiness')
                        ->hiddenLabel()
                        ->state(fn (PaymentProvider $record): string => static::describeReadiness($record))
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /**
     * The lifecycle verbs, as TABLE actions.
     *
     * Filament keeps two sibling action classes (Filament\Actions\Action for page headers,
     * Filament\Tables\Actions\Action for rows) that share a base but are not substitutable.
     * The verbs are therefore defined once in applyLifecycle() and instantiated twice, so
     * the row menu and the view page cannot drift apart.
     *
     * @return list<Tables\Actions\Action>
     */
    public static function tableLifecycleActions(): array
    {
        return array_map(
            static fn (string $verb): Tables\Actions\Action => static::applyLifecycle(Tables\Actions\Action::make($verb), $verb),
            self::LIFECYCLE_VERBS,
        );
    }

    /**
     * The lifecycle verbs, as HEADER actions on the view page.
     *
     * @return list<\Filament\Actions\Action>
     */
    public static function headerLifecycleActions(): array
    {
        return array_map(
            static fn (string $verb): \Filament\Actions\Action => static::applyLifecycle(\Filament\Actions\Action::make($verb), $verb),
            self::LIFECYCLE_VERBS,
        );
    }

    /**
     * Configure one verb on whichever action class the surface uses.
     */
    /**
     * @template TAction of \Filament\Actions\MountableAction
     *
     * @param  TAction  $action
     * @return TAction
     */
    protected static function applyLifecycle(\Filament\Actions\MountableAction $action, string $verb): \Filament\Actions\MountableAction
    {
        $target = static::targetFor($verb);
        $icon = static::iconFor($verb);
        $colour = static::colourFor($verb);

        $reason = Forms\Components\Textarea::make('reason')
            ->label('Reason (recorded in the audit trail)')
            ->required()
            ->minLength(10)
            ->maxLength(255);

        $action = $action
            ->label(ucfirst($verb))
            ->icon($icon)
            ->color($colour)
            ->visible(fn (PaymentProvider $record): bool => AdminAccess::permits(AdminPermission::PROVIDERS_MANAGE)
                && $record->status->canTransitionTo($target))
            ->form([$reason])
            ->requiresConfirmation()
            ->action(fn (PaymentProvider $record, array $data) => static::transition(
                $record,
                $target,
                (string) $data['reason'],
            ));

        return $action->modalDescription(static::consequenceOf($verb));
    }

    /**
     * The status a verb means.
     *
     * The three helpers below existed inline as `match` expressions without a default arm. Every
     * caller passes a value from LIFECYCLE_VERBS, so today it cannot happen — but "cannot happen
     * because everyone remembers" is how an UnhandledMatchError reaches a Friday deploy. An
     * unknown verb is now a named exception instead.
     */
    protected static function targetFor(string $verb): PaymentProviderStatus
    {
        return match ($verb) {
            'activate' => PaymentProviderStatus::Active,
            'suspend' => PaymentProviderStatus::Suspended,
            'disable' => PaymentProviderStatus::Disabled,
            default => throw new \InvalidArgumentException('Unknown lifecycle verb: '.$verb),
        };
    }

    protected static function iconFor(string $verb): string
    {
        return match ($verb) {
            'activate' => 'heroicon-o-play',
            'suspend' => 'heroicon-o-pause',
            'disable' => 'heroicon-o-x-circle',
            default => throw new \InvalidArgumentException('Unknown lifecycle verb: '.$verb),
        };
    }

    protected static function colourFor(string $verb): string
    {
        return match ($verb) {
            'activate' => 'success',
            'suspend' => 'warning',
            'disable' => 'danger',
            default => throw new \InvalidArgumentException('Unknown lifecycle verb: '.$verb),
        };
    }

    protected static function consequenceOf(string $verb): string
    {
        return match ($verb) {
            'suspend' => 'Suspending takes this provider out of traffic. Deposits and withdrawals routed to it stop resolving.',
            'disable' => 'Disabling is permanent: PaymentProviderStatus has no transition out of it. Nothing will resolve against this provider again.',
            'activate' => 'This puts the provider into traffic. It will resolve every method offer it advertises.',
            default => throw new \InvalidArgumentException('Unknown lifecycle verb: '.$verb),
        };
    }

    /**
     * Run a lifecycle transition through the registry — the only writer of status.
     *
     * The registry throws for an illegal transition, and that is reported as a
     * notification rather than a stack trace: the enum is the authority, so a refusal
     * here means the screen was stale, not that the operator did something wrong.
     */
    public static function transition(PaymentProvider $provider, PaymentProviderStatus $target, string $reason): void
    {
        try {
            app(PaymentProviderRegistry::class)->transitionProvider($provider, $target, $reason);

            Notification::make()
                ->title(sprintf('%s is now %s', $provider->code, $target->label()))
                ->body('The transition was audited.')
                ->success()
                ->send();
        } catch (Throwable $e) {
            Notification::make()
                ->title('Transition refused')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public static function statusColor(PaymentProviderStatus $status): string
    {
        return match ($status) {
            PaymentProviderStatus::Active => 'success',
            PaymentProviderStatus::Pending => 'gray',
            PaymentProviderStatus::Suspended => 'warning',
            PaymentProviderStatus::Disabled => 'danger',
        };
    }

    /**
     * Describe the non-secret configuration without printing anything secret-shaped.
     *
     * The column is called `non_secrets` and the registry refuses secret-smelling keys,
     * but a key like "webhook_hint" could still carry a fragment of a URL token. Values
     * are therefore only PRINTED when their key does not look sensitive; otherwise the
     * operator is told the key exists and whether it is set.
     */
    public static function describeNonSecrets(PaymentProvider $provider): string
    {
        $values = (array) ($provider->non_secrets ?? []);

        if ($values === []) {
            return 'None recorded.';
        }

        $parts = [];

        foreach ($values as $key => $value) {
            $key = (string) $key;

            if (preg_match('/(secret|token|password|key|credential|signature|webhook)/i', $key) === 1) {
                $parts[] = sprintf('%s: %s', $key, static::isSet($value) ? '•••• set' : '— not set');

                continue;
            }

            $parts[] = sprintf('%s: %s', $key, is_scalar($value) ? (string) $value : json_encode($value));
        }

        return implode(' · ', $parts);
    }

    /**
     * What the gateway manager says about this provider's driver right now.
     */
    public static function describeReadiness(PaymentProvider $provider): string
    {
        try {
            $availability = app(PaymentGatewayManager::class)->availability();
        } catch (Throwable $e) {
            return 'Readiness unavailable: '.$e->getMessage();
        }

        $driver = (string) $provider->driver;

        if (! isset($availability[$driver])) {
            return sprintf('No runtime driver is registered under [%s]; this row cannot carry traffic.', $driver);
        }

        $row = $availability[$driver];

        return sprintf(
            'driver %s — status %s, enabled %s, deposits %s, withdrawals %s',
            $driver,
            $row['status'],
            $row['enabled'] ? 'yes' : 'no',
            $row['supports_deposit'] ? 'yes' : 'no',
            $row['supports_withdrawal'] ? 'yes' : 'no',
        );
    }

    protected static function isSet(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [];
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
            'index' => Pages\ListPaymentProviders::route('/'),
            'view' => Pages\ViewPaymentProvider::route('/{record}'),
        ];
    }
}
