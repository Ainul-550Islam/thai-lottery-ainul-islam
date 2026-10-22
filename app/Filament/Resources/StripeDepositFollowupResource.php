<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\StripeDepositFollowupResource\Pages\ListStripeDepositFollowups;
use App\Models\StripeDepositFollowup;
use App\Services\Payment\DepositCheckoutService;
use App\Services\Payment\DepositProviderAccountService;
use App\Services\Payment\DepositReviewAccess;
use App\Services\Payment\StripeFollowupRoutingService;
use App\Services\Payment\StripeFollowupSweepService;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\OperatorActionFactory;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/** Global scoped backlog. Encrypted raw events stay private; row routing cannot select a target deposit. */
final class StripeDepositFollowupResource extends Resource
{
    protected static ?string $model = StripeDepositFollowup::class;

    protected static ?string $navigationGroup = 'Finance';

    protected static ?string $navigationIcon = 'heroicon-o-inbox-stack';

    protected static ?string $navigationLabel = 'Stripe follow-up inbox';

    protected static ?int $navigationSort = 12;

    public static function canViewAny(): bool
    {
        return config('payment.deposit_followup_routing.stripe.enabled', false)
            && config('payment.deposit_review.stripe.enabled', false)
            && app(DepositReviewAccess::class)->can(AdminAccess::operator());
    }

    public static function canView(Model $record): bool
    {
        return self::canViewAny() && $record->account_scope === app(DepositProviderAccountService::class)->current('stripe')
            && $record->configuration_hash === app(DepositCheckoutService::class)->stripeConfigurationFingerprint();
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

    public static function getEloquentQuery(): Builder
    {
        app(StripeFollowupRoutingService::class)->authorize(AdminAccess::operator());

        return parent::getEloquentQuery()->useWritePdo()
            ->where('account_scope', app(DepositProviderAccountService::class)->current('stripe'))
            ->where('configuration_hash', app(DepositCheckoutService::class)->stripeConfigurationFingerprint())
            ->with(['latestRouting', 'sweepWork']);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->paginated([10, 25, 50])->columns([
            Tables\Columns\TextColumn::make('id')->sortable(),
            Tables\Columns\TextColumn::make('event_id')->label('Signed event')->searchable(),
            Tables\Columns\TextColumn::make('event_type')->label('Event type')->searchable(),
            Tables\Columns\TextColumn::make('created_at')->label('Received')->dateTime()->sortable(),
            Tables\Columns\TextColumn::make('sweepWork.state')->label('Last worker hint')->placeholder('Not enrolled')->badge(),
            Tables\Columns\TextColumn::make('sweepWork.budget_used')->label('Worker attempts')->placeholder('None'),
            Tables\Columns\TextColumn::make('latestRouting.state')->label('Last routing hint')->placeholder('Not routed')->badge(),
            Tables\Columns\TextColumn::make('latestRouting.deposit_id')->label('Discovered deposit')->placeholder('Unknown'),
            Tables\Columns\TextColumn::make('latestRouting.case_version')->label('Last observed case version')->placeholder('None'),
        ])->filters([
            Tables\Filters\Filter::make('not_routed')->label('Never routed')->query(fn (Builder $q) => $q->doesntHave('latestRouting')),
            Tables\Filters\Filter::make('unmatched')->label('Last attempt unmatched')->query(fn (Builder $q) => $q->whereHas('latestRouting', fn (Builder $q) => $q->where('state', 'unmatched'))),
        ])->actions([
            Tables\Actions\Action::make('retrySweep')->label('Retry held worker item')
                ->visible(fn (StripeDepositFollowup $record): bool => (bool) config('payment.deposit_followup_sweep.stripe.enabled', false) && $record->sweepWork?->state === 'held')
                ->requiresConfirmation()->modalDescription('Resets only the bounded worker retry budget, preserving generation and history. Does not acknowledge a case, clear risk or change money.')
                ->form([Select::make('reason')->required()->options([
                    'capture_now_visible' => 'Original capture now visible', 'evidence_investigated' => 'Evidence investigated', 'operational_retry' => 'Operational retry',
                ])])->action(static function (StripeDepositFollowup $record, array $data): void {
                    OperatorActionFactory::run(static function () use ($record, $data): void {
                        app(StripeFollowupSweepService::class)->retryHeld((int) $record->id, AdminAccess::operator(), $data['reason']);
                        Notification::make()->info()->title('Worker retry queued — no financial resolution')->send();
                    });
                }),
            Tables\Actions\Action::make('routeOriginal')->label('Find original and inspect')->icon('heroicon-o-magnifying-glass')
                ->requiresConfirmation()->modalDescription('Finds the original confirmed capture from this signed event and refreshes its review case. No refund, credit, reversal, acknowledgement or withdrawal change. Unmatched is not a safe negative outcome.')
                ->action(fn (StripeDepositFollowup $record) => self::routeRecord($record)),
            Tables\Actions\Action::make('routingHistory')->label('Routing history')->icon('heroicon-o-clock')
                ->action(static function (StripeDepositFollowup $record): void {
                    OperatorActionFactory::run(static function () use ($record): void {
                        $rows = app(StripeFollowupRoutingService::class)->history((int) $record->id, AdminAccess::operator());
                        Notification::make()->info()->title('Routing history — not financial resolution')
                            ->body('<pre>'.e(json_encode($rows, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)).'</pre>')->persistent()->send();
                    });
                }),
        ])->bulkActions([
            Tables\Actions\BulkAction::make('routeSelected')->label('Route selected — at most25')->requiresConfirmation()
                ->modalDescription('Each selected event commits independently. Earlier successful rows remain committed if a later row fails or staff permission is revoked. No financial action or risk clearance.')
                ->action(static function (Collection $records): void {
                    OperatorActionFactory::run(static function () use ($records): void {
                        $results = app(StripeFollowupRoutingService::class)->routeBatch($records->map(fn ($r) => (int) $r->id)->all(), AdminAccess::operator());
                        Notification::make()->info()->title('Independent routing results')
                            ->body('<pre>'.e(json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)).'</pre>')->persistent()->send();
                    });
                })->deselectRecordsAfterCompletion(),
        ]);
    }

    public static function routeRecord(StripeDepositFollowup $record): void
    {
        abort_unless(AdminAccess::canAccessPanel(AdminAccess::operator()), 403);
        OperatorActionFactory::run(static function () use ($record): void {
            $result = app(StripeFollowupRoutingService::class)->route((int) $record->id, AdminAccess::operator());
            Notification::make()->info()->title($result['state'] === 'routed' ? 'Original deposit review refreshed' : 'Unmatched — retained for later investigation')
                ->body('<pre>'.e(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)).'</pre>')->persistent()->send();
        });
    }

    public static function getPages(): array
    {
        return ['index' => ListStripeDepositFollowups::route('/')];
    }
}
