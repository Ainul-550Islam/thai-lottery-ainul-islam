<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\GameRulesStatus;
use App\Exceptions\LotteryGameException;
use App\Filament\Resources\LotteryGameResource\Pages\ListLotteryGames;
use App\Models\LotteryGame;
use App\Services\LotteryGames\GameAuditRecorder;
use App\Services\LotteryGames\GameAvailabilityService;
use App\Services\LotteryGames\GameRegistry;
use App\Services\LotteryGames\GameRulesValidator;
use App\Services\LotteryGames\RtpAnalyzer;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminFormat;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The operator console for digit games: what is configured, who verified it, and what is
 * stopping it from selling.
 *
 * WHY THERE IS NO CREATE AND NO DELETE
 * A game is DECLARED in config/lottery_games.php — its rules, its markets, its draw
 * calendar and its result source are a code review, not a form. This screen therefore
 * governs the only thing an operator may decide: has the rule set been verified, is the
 * game switched on, and should it be withdrawn. A "create game" button would let an
 * operator invent a product with no rules behind it, which is precisely what Rule §0.3
 * forbids; a delete button would erase the audit trail that suspension preserves.
 *
 * THE THREE ACTIONS, AND WHY EACH RE-CHECKS BEFORE WRITING
 *   Verify rules   hashes the LIVE configuration, runs GameRulesValidator AND RtpAnalyzer,
 *                  and refuses if any REQUIRED_INPUT remains, if the rule set contains a
 *                  contradiction, or if a market's RTP breaches the operator's ceiling.
 *                  Only then does it stamp the checksum it just computed — so the stamp
 *                  always refers to the exact block that was validated.
 *   Suspend        withdraws trust without erasing who gave it, and switches the game off.
 *   Resume         puts a suspended game back ONLY if the stored checksum still matches
 *                  the deployed configuration. A resume that ignored the checksum would be
 *                  a way to re-verify an edited payout table with one click.
 *
 * WHAT THE TABLE SHOWS, AND WHY EACH COLUMN IS AN OPERATOR QUESTION
 *   Sellable?      the nine gates, summarised, with the first failing reason as a tooltip
 *   Checksum drift whether the deployed configuration still matches the verified one
 *   RTP ceiling    whether the configured multipliers pay back more than the operator allows
 * Those are the three ways a game that "looks fine" is actually not able to sell or is
 * quietly losing money, and none of them is visible from the rules table alone.
 *
 * NO BUSINESS LOGIC LIVES IN THIS FILE
 * Verification validity, gate evaluation, RTP arithmetic and the checksum itself are
 * services. This resource calls them and renders what they say; every write goes through
 * the LotteryGame model's own audited methods.
 */
class LotteryGameResource extends Resource
{
    protected static ?string $model = LotteryGame::class;

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationGroup = 'Lottery';

    protected static ?string $navigationLabel = 'Digit games';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Declared explicitly: an inherited canViewAny() would let any account the panel
     * admits open this screen (the framework default is permissive, which is exactly the
     * mistake this method prevents).
     *
     * WHY THE AUDITOR DOES NOT OPEN THIS SCREEN
     * This is the Writable console: verify a payout table, suspend a game, enable selling.
     * Every control on it is a write or a precondition for one, so the permission that
     * governs it is the one that governs draws. The read-only auditor seat does not hold it,
     * and giving it the screen would mean either showing an auditor buttons it may never
     * press or maintaining a second, view-only copy of the same table. The auditor's version
     * of this page is the rules report, which is read-only by construction and which it
     * reaches through `view financial reports`.
     */
    public static function canViewAny(): bool
    {
        return AdminAccess::current(AdminAccess::VIEW_DRAWS);
    }

    public static function canView(Model $record): bool
    {
        return AdminAccess::current(AdminAccess::VIEW_DRAWS);
    }

    public static function canCreate(): bool
    {
        // Games are declared in configuration. See the class comment.
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return AdminAccess::current(AdminAccess::MANAGE_DRAWS)
            && ! AdminAccess::isReadOnly();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * How many games are NOT sellable right now.
     *
     * A badge, not a count of rows: an operator opening the panel wants to know whether
     * anything needs attention, and a game that is verified and selling needs none.
     */
    public static function getNavigationBadge(): ?string
    {
        $blocked = LotteryGame::query()
            ->where(function (Builder $query): void {
                $query->where('status', '!=', GameRulesStatus::Verified->value)
                    ->orWhere('enabled', false);
            })
            ->count();

        return $blocked > 0 ? (string) $blocked : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Games that are unverified, suspended or switched off';
    }

    public static function table(Table $table): Table
    {
        $registry = app(GameRegistry::class);
        $availability = app(GameAvailabilityService::class);

        return $table
            ->defaultSort('code')
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Code')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->weight('bold')
                    ->description(fn (LotteryGame $record): string => (string) $record->name),

                Tables\Columns\TextColumn::make('status')
                    ->label('Rules')
                    ->badge()
                    ->formatStateUsing(fn (GameRulesStatus $state): string => $state->label())
                    ->color(fn (GameRulesStatus $state): string => match ($state) {
                        GameRulesStatus::Verified => 'success',
                        GameRulesStatus::Unverified => 'warning',
                        GameRulesStatus::Suspended => 'danger',
                    })
                    ->sortable(),

                Tables\Columns\IconColumn::make('enabled')
                    ->label('Selling')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('gray'),

                Tables\Columns\TextColumn::make('checksum')
                    ->label('Checksum')
                    ->state(function (LotteryGame $record) use ($registry): string {
                        $governance = $registry->governance((string) $record->code);

                        if ($governance['checksum_stored'] === null) {
                            return 'never verified';
                        }

                        return $governance['checksum_matches'] ? 'matches deployed rules' : 'DRIFTED';
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'never verified' => 'warning',
                        'DRIFTED' => 'danger',
                        default => 'success',
                    })
                    ->tooltip(fn (LotteryGame $record): string => (string) ($registry->governance((string) $record->code)['checksum_stored'] ?? '—')),

                Tables\Columns\TextColumn::make('sellable')
                    ->label('Sellable')
                    ->state(function (LotteryGame $record) use ($availability): string {
                        $capability = $availability->capability((string) $record->code);

                        return $capability->isSellable() ? 'yes' : (string) $capability->reason;
                    })
                    ->badge()
                    ->color(fn (string $state): string => $state === 'yes' ? 'success' : 'gray')
                    ->tooltip(function (LotteryGame $record) use ($availability): string {
                        $capability = $availability->capability((string) $record->code);
                        $failing = array_keys(array_filter($capability->gates, static fn (bool $passed): bool => $passed === false));

                        return $failing === []
                            ? 'all nine gates pass'
                            : 'failing gates: '.implode(', ', $failing);
                    }),

                Tables\Columns\TextColumn::make('rtp')
                    ->label('RTP')
                    ->state(function (LotteryGame $record) use ($registry): string {
                        $definition = $registry->find((string) $record->code);

                        if ($definition === null) {
                            return 'not declared';
                        }

                        $analysis = (new RtpAnalyzer)->analyze($definition, $registry->rtpCeiling());

                        if ($analysis['breaches'] !== []) {
                            return count($analysis['breaches']).' breach(es)';
                        }

                        return 'within ceiling';
                    })
                    ->badge()
                    ->color(fn (string $state): string => str_contains($state, 'breach') ? 'danger' : 'success'),

                Tables\Columns\TextColumn::make('verified_at')
                    ->label('Verified ('.AdminFormat::timezoneLabel().')')
                    ->formatStateUsing(fn ($state): string => $state === null ? '—' : AdminFormat::marketTime($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('verifier.email')
                    ->label('Verified by')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->actions([
                Tables\Actions\Action::make('verify')
                    ->label('Verify rules')
                    ->icon('heroicon-o-shield-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Verify the deployed rule set')
                    ->modalDescription('The checksum of the configuration currently deployed will be stamped. Any later edit to those rules withdraws the verification automatically.')
                    ->visible(fn (LotteryGame $record): bool => static::canEdit($record))
                    ->action(function (LotteryGame $record): void {
                        static::verifyRules($record);
                    }),

                Tables\Actions\Action::make('suspend')
                    ->label('Suspend')
                    ->icon('heroicon-o-pause-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Suspend this game')
                    ->modalDescription('Selling stops immediately. The verification history is kept, including who verified which rule set.')
                    ->visible(fn (LotteryGame $record): bool => static::canEdit($record)
                        && $record->status !== GameRulesStatus::Suspended)
                    ->action(function (LotteryGame $record): void {
                        $operator = auth()->id();

                        if (! is_int($operator)) {
                            Notification::make()->title('No operator seat')->danger()->send();

                            return;
                        }

                        $record->markSuspended($operator, 'suspended from the digit games console');

                        // Switching selling off is part of suspending: a suspended game that
                        // stayed enabled would still be refused by the rules gate, but the
                        // column would lie.
                        $record->forceFill(['enabled' => false])->save();

                        app(GameAuditRecorder::class)->record(
                            GameAuditRecorder::GAME_SUSPENDED,
                            (string) $record->code,
                            $operator,
                            'game',
                            (string) $record->code,
                            ['reason' => 'suspended from the digit games console'],
                        );

                        Notification::make()->title('Game suspended')->warning()->send();
                    }),

                Tables\Actions\Action::make('resume')
                    ->label('Resume')
                    ->icon('heroicon-o-play-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Put this game back into service')
                    ->modalDescription('Allowed only while the deployed rule set still matches the verified checksum.')
                    ->visible(fn (LotteryGame $record): bool => static::canEdit($record)
                        && $record->status === GameRulesStatus::Suspended)
                    ->action(function (LotteryGame $record): void {
                        $registry = app(GameRegistry::class);
                        $definition = $registry->find((string) $record->code);

                        if ($definition === null) {
                            Notification::make()->title('Game is not declared in configuration')->danger()->send();

                            return;
                        }

                        $stored = (string) ($record->rules_checksum ?? '');

                        if ($stored === '' || ! hash_equals($stored, $definition->checksum())) {
                            // A resume that ignored the checksum would re-verify an edited
                            // payout table in one click.
                            Notification::make()
                                ->title('The deployed rules no longer match the verified checksum')
                                ->body('Verify the rules again instead of resuming.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $record->forceFill([
                            'status' => GameRulesStatus::Verified,
                            'enabled' => true,
                        ])->save();

                        app(GameAuditRecorder::class)->record(
                            GameAuditRecorder::GAME_RESUMED,
                            (string) $record->code,
                            is_int(auth()->id()) ? auth()->id() : null,
                            'game',
                            (string) $record->code,
                            ['rules_checksum' => $stored],
                        );

                        Notification::make()->title('Game resumed')->success()->send();
                    }),

                Tables\Actions\Action::make('enable')
                    ->label('Enable selling')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Switch selling on')
                    ->modalDescription('Allowed only for a game whose deployed rules still match the verified checksum. The other availability gates still apply to every bet.')
                    ->visible(fn (LotteryGame $record): bool => static::canEdit($record)
                        && $record->status === GameRulesStatus::Verified
                        && $record->enabled !== true)
                    ->action(function (LotteryGame $record): void {
                        static::setEnabled($record, true);
                    }),

                Tables\Actions\Action::make('disable')
                    ->label('Disable selling')
                    ->icon('heroicon-o-stop')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Switch selling off')
                    ->modalDescription('Selling stops immediately. The verification stays; use Suspend to withdraw it.')
                    ->visible(fn (LotteryGame $record): bool => static::canEdit($record)
                        && $record->enabled === true)
                    ->action(function (LotteryGame $record): void {
                        static::setEnabled($record, false);
                    }),

                Tables\Actions\Action::make('rules')
                    ->label('Rules report')
                    ->icon('heroicon-o-document-text')
                    ->color('gray')
                    ->url(fn (): string => \App\Filament\Pages\GameRulesReportPage::getUrl()),
            ]);
    }

    /**
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListLotteryGames::route('/'),
        ];
    }

    /**
     * Verify the DEPLOYED rule set, and stamp only what was actually validated.
     *
     * The order is the whole control:
     *   1. the game must be declared in configuration at all,
     *   2. no REQUIRED_INPUT may remain (an operator cannot verify a placeholder),
     *   3. the rule set must not contradict itself,
     *   4. no market's RTP may exceed the operator's ceiling,
     *   5. only then is the checksum computed and stamped.
     *
     * A failure at any step is reported as a notification naming the specific reason, and
     * NOTHING is written — in particular `enabled` is not switched on by a failed
     * verification, so a half-verified game cannot start selling.
     */
    private static function verifyRules(LotteryGame $record): void
    {
        $operator = auth()->id();

        if (! is_int($operator)) {
            Notification::make()->title('No operator seat')->danger()->send();

            return;
        }

        $registry = app(GameRegistry::class);
        $definition = $registry->find((string) $record->code);

        if ($definition === null) {
            static::auditRefusal($record, $operator, 'not declared in configuration', []);

            Notification::make()
                ->title('Not declared in config/lottery_games.php')
                ->body('A game is declared in configuration first; there is nothing to verify.')
                ->danger()
                ->send();

            return;
        }

        $report = (new GameRulesValidator)->validate($definition);

        if ($report['missing'] !== []) {
            static::auditRefusal($record, $operator, 'REQUIRED_INPUT remains', $report['missing']);

            Notification::make()
                ->title('REQUIRED_INPUT remains: '.count($report['missing']).' value(s)')
                ->body(implode(', ', array_slice($report['missing'], 0, 8)))
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        if ($report['problems'] !== []) {
            static::auditRefusal($record, $operator, 'the rule set contradicts itself', $report['problems']);

            Notification::make()
                ->title('The rule set contradicts itself')
                ->body(implode(' | ', array_slice($report['problems'], 0, 4)))
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        $analysis = (new RtpAnalyzer)->analyze($definition, $registry->rtpCeiling());

        if ($analysis['breaches'] !== []) {
            static::auditRefusal($record, $operator, 'RTP breaches the ceiling', $analysis['breaches']);

            Notification::make()
                ->title('RTP breaches the configured ceiling of '.$analysis['ceiling'])
                ->body(implode(' | ', array_slice($analysis['breaches'], 0, 4)))
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        try {
            $record->markVerified($operator, $definition->checksum(), [
                'verified_from' => 'Filament:DigitGames',
                'bet_types' => $definition->betTypeKeys(),
                'rtp_ceiling' => $analysis['ceiling'],
            ]);

            // Whether a verification also switches selling on is the operator's policy,
            // not a constant: the default (true) keeps the one-click behaviour, and a
            // deployment that wants verify and enable to be two deliberate decisions sets
            // lottery_games.admin.verify_enables_game to false and uses "Enable selling".
            $enables = (bool) config('lottery_games.admin.verify_enables_game', true);

            if ($enables) {
                $record->forceFill(['enabled' => true])->save();
            }
        } catch (LotteryGameException $e) {
            static::auditRefusal($record, $operator, $e->errorCode(), [$e->getMessage()]);

            Notification::make()->title($e->errorCode())->body($e->getMessage())->danger()->send();

            return;
        }

        app(GameAuditRecorder::class)->record(
            GameAuditRecorder::RULES_VERIFIED,
            (string) $record->code,
            $operator,
            'game',
            (string) $record->code,
            [
                'rules_checksum' => $definition->checksum(),
                'bet_types' => $definition->betTypeKeys(),
                'rtp_ceiling' => $analysis['ceiling'],
                'enabled_by_verification' => $enables,
            ],
        );

        Notification::make()
            ->title($enables ? 'Rules verified and the game enabled' : 'Rules verified; selling is still switched off')
            ->body('Checksum stamped: '.substr($definition->checksum(), 0, 16).'…')
            ->success()
            ->send();
    }

    /**
     * Switch selling on or off for a game, without touching its verification.
     *
     * Switching ON re-checks the verification against the rules deployed right now: a
     * game whose payout table changed after it was verified must be verified again, not
     * switched on by a click that never looked at the checksum. Switching OFF always
     * works - turning selling off is never the dangerous direction.
     */
    private static function setEnabled(LotteryGame $record, bool $enabled): void
    {
        $operator = auth()->id();

        if (! is_int($operator)) {
            Notification::make()->title('No operator seat')->danger()->send();

            return;
        }

        if ($enabled && ! app(GameRegistry::class)->isVerifiedAgainstCurrentRules((string) $record->code)) {
            static::auditRefusal($record, $operator, 'enable refused: rules not verified against the deployed checksum', []);

            Notification::make()
                ->title('The deployed rules are not verified')
                ->body('Verify the rules again before switching selling on.')
                ->danger()
                ->send();

            return;
        }

        $record->forceFill(['enabled' => $enabled])->save();

        app(GameAuditRecorder::class)->record(
            $enabled ? GameAuditRecorder::GAME_ENABLED : GameAuditRecorder::GAME_DISABLED,
            (string) $record->code,
            $operator,
            'game',
            (string) $record->code,
            ['rules_checksum' => (string) ($record->rules_checksum ?? '')],
        );

        Notification::make()
            ->title($enabled ? 'Selling switched on' : 'Selling switched off')
            ->success()
            ->send();
    }

    /**
     * A refused operator decision is part of the trail too: "somebody tried to verify
     * a payout table that still held REQUIRED_INPUT" is exactly what an auditor asks.
     *
     * @param  list<string>  $details
     */
    private static function auditRefusal(LotteryGame $record, int $operator, string $reason, array $details): void
    {
        app(GameAuditRecorder::class)->record(
            GameAuditRecorder::RULES_VERIFICATION_REFUSED,
            (string) $record->code,
            $operator,
            'game',
            (string) $record->code,
            ['reason' => $reason, 'details' => array_slice($details, 0, 20)],
        );
    }
}
