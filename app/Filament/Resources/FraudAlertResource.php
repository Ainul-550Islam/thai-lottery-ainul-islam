<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\FraudAlertResource\Pages;
use App\Filament\Resources\UserResource;
use App\Models\FraudAlert;
use App\Models\User;
use App\Services\Fraud\FraudAlertReviewService;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * The fraud queue — the screen an analyst actually works from.
 *
 * WHY THIS EXISTS
 *
 * FraudAlertReviewService has been the canonical adjudication path for a while: it locks the
 * alert, refuses one that is already resolved, enforces maker-checker (the account under
 * review may not adjudicate its own alert), writes the audit line and creates the FraudCase
 * row. Nothing in the panel could reach it, so alerts were reviewed — if at all — from a
 * console. The compliance widget has shown the count since it landed; a count you cannot act
 * on is an alarm with no handle.
 *
 * WHAT THIS SCREEN DELIBERATELY DOES NOT DO
 *
 *   - It does not write FraudAlert.status. Every verb goes through
 *     FraudAlertReviewService::review(), which owns the state machine, the terminal rule, the
 *     maker-checker rule and the audit. There is no edit and no delete: an adjudication is
 *     not a field.
 *   - It does not re-implement maker-checker. The service throws for a self-review; this
 *     screen hides the button as a courtesy, but the service is the rule.
 *   - It does not GUESS who the subject is. `fraud_alerts.user_id` is a uuid column and
 *     `users.id` is a bigint, so the identifier the lane stamped is shown as it is; a link to the
 *     account appears only when the resolved pointer (`player_user_id`) exists, which the recorder
 *     fills in when the subject really is a numeric account id. No link is better than a link to
 *     the wrong player.
 *
 * PERMISSIONS: 'fraud.view' to read, 'fraud.review' to adjudicate — two grants, because
 * reading a suspicion and closing it are different powers.
 */
class FraudAlertResource extends Resource
{
    protected static ?string $model = FraudAlert::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static ?string $navigationGroup = 'Risk';

    protected static ?string $navigationLabel = 'Fraud alerts';

    protected static ?string $modelLabel = 'fraud alert';

    protected static ?string $pluralModelLabel = 'fraud alerts';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'id';

    /** @var list<string> */
    private const OPEN_STATUSES = ['OPEN', 'UNDER_REVIEW'];

    public static function canViewAny(): bool
    {
        return AdminAccess::permitsAny([
            AdminPermission::FRAUD_VIEW,
            AdminPermission::FRAUD_REVIEW,
        ]);
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof FraudAlert && static::canViewAny();
    }

    public static function canCreate(): bool
    {
        // Alerts are raised by the detection lanes from real events, never typed in.
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        // An adjudicated alert is evidence. It is never deleted from a panel.
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $open = FraudAlert::query()->whereIn('status', self::OPEN_STATUSES)->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query)
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('Alert')
                    ->formatStateUsing(fn (string $state): string => substr($state, 0, 8))
                    ->copyable()
                    ->tooltip(fn (FraudAlert $record): string => (string) $record->id),

                Tables\Columns\TextColumn::make('event_type')
                    ->label('Event')
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('subject')
                    ->label('Subject')
                    ->state(fn (FraudAlert $record): string => static::subjectLabel($record))
                    ->url(fn (FraudAlert $record): ?string => static::subjectUrl($record))
                    ->openUrlInNewTab()
                    ->copyable()
                    ->limit(24)
                    ->tooltip(fn (FraudAlert $record): string => static::subjectTooltip($record)),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state, FraudAlert $record): string => AdminFormat::money($state, (string) $record->currency))
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('risk_score')
                    ->label('Score')
                    ->badge()
                    ->color(fn (int $state): string => match (true) {
                        $state >= 70 => 'danger',
                        $state >= 40 => 'warning',
                        default => 'success',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('decision')
                    ->label('Lane decision')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => static::statusColor($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Raised')
                    ->since()
                    ->tooltip(fn ($state): string => AdminFormat::marketTime($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('resolved_at')
                    ->label('Adjudicated')
                    ->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(fn (): array => FraudAlert::query()
                        ->distinct()
                        ->orderBy('status')
                        ->pluck('status', 'status')
                        ->all())
                    ->multiple(),

                Tables\Filters\SelectFilter::make('event_type')
                    ->label('Event')
                    ->options(fn (): array => FraudAlert::query()
                        ->distinct()
                        ->orderBy('event_type')
                        ->pluck('event_type', 'event_type')
                        ->all()),

                Tables\Filters\Filter::make('high_risk')
                    ->label('Risk score 70+')
                    ->query(fn (Builder $query): Builder => $query->where('risk_score', '>=', 70))
                    ->toggle(),

                Tables\Filters\Filter::make('awaiting_adjudication')
                    ->label('Awaiting adjudication')
                    ->query(fn (Builder $query): Builder => $query->whereIn('status', self::OPEN_STATUSES))
                    ->toggle(),

                Tables\Filters\Filter::make('raised_between')
                    ->label('Raised between')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('From'),
                        Forms\Components\DatePicker::make('until')->label('Until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $from): Builder => $q->whereDate('created_at', '>=', $from))
                        ->when($data['until'] ?? null, fn (Builder $q, $until): Builder => $q->whereDate('created_at', '<=', $until))),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('release_allow')
                        ->label('Release — allow')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->visible(fn (FraudAlert $record): bool => static::mayAdjudicate($record))
                        ->form([static::reasonField('Why is this alert being released?')])
                        ->requiresConfirmation()
                        ->modalDescription('The alert closes as RESOLVED_ALLOW. This is terminal: it cannot be flipped afterwards.')
                        ->action(fn (FraudAlert $record, array $data) => static::review($record, 'RELEASE_ALLOW', (string) $data['reason'])),

                    Tables\Actions\Action::make('reject_deny')
                        ->label('Reject — deny')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->visible(fn (FraudAlert $record): bool => static::mayAdjudicate($record))
                        ->form([static::reasonField('What makes this genuinely fraudulent?')])
                        ->requiresConfirmation()
                        ->modalDescription('The alert closes as RESOLVED_DENY. This is terminal.')
                        ->action(fn (FraudAlert $record, array $data) => static::review($record, 'REJECT_DENY', (string) $data['reason'])),

                    Tables\Actions\Action::make('freeze_account')
                        ->label('Freeze the account')
                        ->icon('heroicon-o-lock-closed')
                        ->color('warning')
                        ->visible(fn (FraudAlert $record): bool => static::mayAdjudicate($record))
                        ->form([static::reasonField('What is being frozen and why?')])
                        ->requiresConfirmation()
                        ->modalDescription('The alert closes as RESOLVED_ACCOUNT_FROZEN. This is terminal.')
                        ->action(fn (FraudAlert $record, array $data) => static::review($record, 'FREEZE_ACCOUNT', (string) $data['reason'])),
                ])
                    ->label('Adjudicate')
                    ->icon('heroicon-m-shield-check')
                    ->button()
                    ->outlined()
                    ->visible(fn (): bool => AdminAccess::permits(AdminPermission::FRAUD_REVIEW)),
            ])
            ->bulkActions([])
            ->emptyStateHeading('No fraud alerts')
            ->emptyStateDescription('Alerts appear here as the detection lanes raise them.');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Alert')
                ->schema([
                    Infolists\Components\TextEntry::make('id')->label('Alert id')->copyable(),
                    Infolists\Components\TextEntry::make('event_type')->label('Event')->badge(),
                    Infolists\Components\TextEntry::make('status')
                        ->badge()
                        ->color(fn (string $state): string => static::statusColor($state)),
                    Infolists\Components\TextEntry::make('subject')
                        ->label('Subject identifier')
                        ->state(fn (FraudAlert $record): string => (string) $record->user_id)
                        ->copyable()
                        ->helperText('Exactly what the fraud lane stamped. It is not coerced into an account id.')
                        ->columnSpan(2),
                    Infolists\Components\TextEntry::make('player')
                        ->label('Account')
                        ->state(fn (FraudAlert $record): string => static::subjectLabel($record) === (string) $record->user_id
                            ? '— no account link (the subject reference does not resolve to one)'
                            : static::subjectLabel($record))
                        ->url(fn (FraudAlert $record): ?string => static::subjectUrl($record))
                        ->columnSpan(2),
                    Infolists\Components\TextEntry::make('created_at')
                        ->label('Raised')
                        ->state(fn (FraudAlert $record): string => AdminFormat::marketTime($record->created_at)),
                    Infolists\Components\TextEntry::make('risk_score')
                        ->label('Risk score')
                        ->badge()
                        ->color(fn (int $state): string => $state >= 70 ? 'danger' : ($state >= 40 ? 'warning' : 'success')),
                ])
                ->columns(3),

            Infolists\Components\Section::make('Transaction under review')
                ->schema([
                    Infolists\Components\TextEntry::make('amount')
                        ->label('Amount')
                        ->formatStateUsing(fn ($state, FraudAlert $record): string => AdminFormat::money($state, (string) $record->currency)),
                    Infolists\Components\TextEntry::make('decision')->label('Detection lane decision'),
                    Infolists\Components\TextEntry::make('currency')->label('Currency'),
                ])
                ->columns(3),

            Infolists\Components\Section::make('Why it fired')
                ->description('Reason codes and rule names as the rules engine produced them. Read-only: the alert is evidence.')
                ->schema([
                    Infolists\Components\TextEntry::make('reason_codes')
                        ->label('Reason codes')
                        ->badge()
                        ->state(fn (FraudAlert $record): array => (array) ($record->reason_codes ?? []))
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('triggered_rules')
                        ->label('Triggered rules')
                        ->badge()
                        ->state(fn (FraudAlert $record): array => (array) ($record->triggered_rules ?? []))
                        ->placeholder('—'),
                ]),

            Infolists\Components\Section::make('Adjudication')
                ->description('Written by FraudAlertReviewService, never by this screen.')
                ->schema([
                    Infolists\Components\TextEntry::make('resolved_by_user_id')
                        ->label('Decided by (user id)')
                        ->placeholder('Not adjudicated'),
                    Infolists\Components\TextEntry::make('resolved_at')
                        ->label('Decided at')
                        ->state(fn (FraudAlert $record): string => AdminFormat::marketTime($record->resolved_at)),
                    Infolists\Components\TextEntry::make('resolution_notes')
                        ->label('Reason given')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->columns(3),

            Infolists\Components\Section::make('Case files')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('cases')
                        ->hiddenLabel()
                        ->schema([
                            Infolists\Components\TextEntry::make('action_taken')->label('Action'),
                            Infolists\Components\TextEntry::make('decision')->label('Outcome'),
                            Infolists\Components\TextEntry::make('reviewer_user_id')->label('Reviewer (user id)'),
                            Infolists\Components\TextEntry::make('decided_at')
                                ->label('Decided')
                                ->state(fn ($record): string => AdminFormat::marketTime($record->decided_at ?? null)),
                            Infolists\Components\TextEntry::make('decision_reason')->label('Reason')->columnSpanFull(),
                        ])
                        ->columns(4)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /**
     * May this operator adjudicate this alert from this screen?
     *
     * The service is the authority; this only decides whether a button is shown. Both checks
     * exist on purpose: hiding the button is a courtesy, the service's refusal is the rule.
     * The self-review check mirrors the maker-checker guard so an operator is never offered a
     * verb that is guaranteed to fail.
     */
    public static function mayAdjudicate(FraudAlert $alert): bool
    {
        if (! AdminAccess::permits(AdminPermission::FRAUD_REVIEW)) {
            return false;
        }

        if (static::isTerminal($alert)) {
            return false;
        }

        $operator = AdminAccess::operator();

        return ! ($operator !== null && (string) $alert->user_id === (string) $operator->getKey());
    }

    public static function isTerminal(FraudAlert $alert): bool
    {
        return str_starts_with(strtoupper((string) $alert->status), 'RESOLVED_');
    }

    /**
     * Hand the decision to the canonical service and report its answer verbatim.
     */
    public static function review(FraudAlert $alert, string $action, string $reason): void
    {
        $operator = AdminAccess::operator();

        if ($operator === null) {
            Notification::make()->title('Refused')->body('No authenticated operator.')->danger()->send();

            return;
        }

        try {
            app(FraudAlertReviewService::class)->review(
                id: (string) $alert->getKey(),
                reviewerId: (string) $operator->getKey(),
                action: $action,
                decisionReason: $reason,
                metadata: ['surface' => 'admin-panel', 'alert_event_type' => (string) $alert->event_type],
            );

            Notification::make()
                ->title('Alert adjudicated')
                ->body('The decision, the case file and the audit line were written.')
                ->success()
                ->send();
        } catch (Throwable $e) {
            // A stale screen, a second reviewer, or a self-review attempt: all are the
            // service's own refusals and are reported as such rather than as a 500.
            Notification::make()
                ->title('Not adjudicated')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public static function statusColor(string $status): string
    {
        return match (strtoupper($status)) {
            'OPEN' => 'danger',
            'UNDER_REVIEW' => 'warning',
            'RESOLVED_ALLOW' => 'success',
            'RESOLVED_DENY' => 'gray',
            'RESOLVED_ACCOUNT_FROZEN' => 'info',
            default => 'gray',
        };
    }

    protected static function reasonField(string $label): Forms\Components\Textarea
    {
        return Forms\Components\Textarea::make('reason')
            ->label($label)
            ->helperText('Recorded on the case file and in the audit trail. PII is redacted by the audit service, not by you.')
            ->required()
            ->minLength(10)
            ->maxLength(255);
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
            'index' => Pages\ListFraudAlerts::route('/'),
            'view' => Pages\ViewFraudAlert::route('/{record}'),
        ];
    }

    /**
     * How the subject is labelled: the account's username when the pointer resolves, otherwise
     * the raw reference the lane stamped.
     */
    public static function subjectLabel(FraudAlert $alert): string
    {
        $player = $alert->player_user_id !== null ? $alert->player()->first() : null;

        return $player instanceof User
            ? (string) ($player->username ?? $player->email ?? $player->getKey())
            : (string) $alert->user_id;
    }

    /**
     * A link to the account, or null when there is nothing honest to link to.
     */
    public static function subjectUrl(FraudAlert $alert): ?string
    {
        if ($alert->player_user_id === null) {
            return null;
        }

        return UserResource::getUrl('view', ['record' => $alert->player_user_id]);
    }

    public static function subjectTooltip(FraudAlert $alert): string
    {
        return $alert->player_user_id === null
            ? 'The identifier the fraud lane stamped on the alert. It does not resolve to an account.'
            : 'The identifier the fraud lane stamped, resolved to account #'.$alert->player_user_id.'.';
    }
}
