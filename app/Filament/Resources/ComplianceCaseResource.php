<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\AmlRiskLevel;
use App\Enums\ComplianceCaseStatus;
use App\Filament\Resources\ComplianceCaseResource\ComplianceCaseActions;
use App\Filament\Resources\ComplianceCaseResource\Pages;
use App\Models\ComplianceCase;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminFormat;
use App\Support\Admin\AdminPermission;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * AML / compliance investigations — the workflow the console could only display.
 *
 * WHY THIS EXISTS
 *
 * ComplianceCaseService owns a real investigation lifecycle: open → investigating →
 * escalated → resolved → closed, with a transition map on the enum, an immutable escalation
 * event, a resolution note and an audit line per move. The `/admin/compliance-cases` routes
 * rendered a read-only projection of that table ("NOT_CONFIGURED" for most columns), so an
 * AML officer could see a case and do nothing with it.
 *
 * WHAT THIS SCREEN DOES NOT DECIDE
 *
 *   - It never sets `status`. Every verb calls the service, which applies
 *     ComplianceCaseStatus::canTransitionTo() and refuses an illegal move by name.
 *   - It never invents a resolution. `resolve()` requires a full-sentence note (8+
 *     characters); the service refuses a one-word adjudication, and this screen passes the
 *     refusal through rather than shortening it to a toast that says "saved".
 *   - It does not close a case for a non-admin. `close()` requires an admin-authority
 *     operator by name; the button is hidden for an AML officer, and the service refuses it
 *     anyway if such a request arrives another way.
 *
 * WHY THE RISK LEVEL IS READ-ONLY HERE
 *
 * `risk_level` is stamped by the AML assessment lane from evidence, not chosen by a reviewer.
 * Editing it from a form would let an investigator downgrade the risk of the case they are
 * investigating, which is exactly the record an auditor would want untouched.
 *
 * PERMISSIONS: 'aml.view' to read, 'aml.review' to move a case.
 */
class ComplianceCaseResource extends Resource
{
    protected static ?string $model = ComplianceCase::class;

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationGroup = 'Compliance';

    protected static ?string $navigationLabel = 'AML cases';

    protected static ?string $modelLabel = 'compliance case';

    protected static ?string $pluralModelLabel = 'compliance cases';

    protected static ?int $navigationSort = 2;

    /**
     * NOT 'compliance-cases'.
     *
     * `routes/web.php` already owns `admin/compliance-cases` (index and `{reference}` show) on
     * ReleaseOperationsController — the legacy read-only projection. Filament registers its
     * resource routes from a panel provider, so whichever route claims the URI first wins and
     * the other one's name is simply never registered; pointing this resource at the same URI
     * deleted its own index route and broke the whole panel's navigation render.
     *
     * `aml-cases` is the honest name anyway: these are AML investigation files, not the
     * generic compliance surface behind that legacy link.
     */
    protected static ?string $slug = 'aml-cases';

    protected static ?string $recordTitleAttribute = 'case_key';

    public static function canViewAny(): bool
    {
        return AdminAccess::permitsAny([
            AdminPermission::AML_VIEW,
            AdminPermission::AML_REVIEW,
        ]);
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof ComplianceCase && static::canViewAny();
    }

    public static function canCreate(): bool
    {
        // Cases are opened by the AML lanes from evidence; a hand-typed case is not a case.
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        // A closed investigation file is a regulatory record.
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $open = ComplianceCase::query()
            ->whereIn('status', [
                ComplianceCaseStatus::Open,
                ComplianceCaseStatus::Investigating,
                ComplianceCaseStatus::Escalated,
            ])
            ->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('subject'))
            ->columns([
                Tables\Columns\TextColumn::make('case_key')
                    ->label('Case')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),

                Tables\Columns\TextColumn::make('subject.username')
                    ->label('Subject')
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('case_type')
                    ->label('Type')
                    ->badge()
                    ->color('gray')
                    ->searchable(),

                Tables\Columns\TextColumn::make('risk_level')
                    ->label('Risk')
                    ->badge()
                    ->formatStateUsing(fn (AmlRiskLevel $state): string => ucfirst($state->value))
                    ->color(fn (AmlRiskLevel $state): string => static::riskColor($state)),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (ComplianceCaseStatus $state): string => ucfirst($state->value))
                    ->color(fn (ComplianceCaseStatus $state): string => static::statusColor($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('assigned_desk')
                    ->label('Desk')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('investigating_since')
                    ->label('Investigating since')
                    ->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Opened')
                    ->since()
                    ->tooltip(fn ($state): string => AdminFormat::marketTime($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('escalated_at')
                    ->label('Escalated')
                    ->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(fn (): array => collect(ComplianceCaseStatus::cases())
                        ->mapWithKeys(fn (ComplianceCaseStatus $status): array => [$status->value => ucfirst($status->value)])
                        ->all())
                    ->multiple(),

                Tables\Filters\SelectFilter::make('risk_level')
                    ->label('Risk')
                    ->options(fn (): array => collect(AmlRiskLevel::cases())
                        ->mapWithKeys(fn (AmlRiskLevel $level): array => [$level->value => ucfirst($level->value)])
                        ->all()),

                Tables\Filters\SelectFilter::make('case_type')
                    ->label('Type')
                    ->options(fn (): array => ComplianceCase::query()
                        ->distinct()
                        ->orderBy('case_type')
                        ->pluck('case_type', 'case_type')
                        ->all()),

                Tables\Filters\Filter::make('high_risk_open')
                    ->label('High risk, still open')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereIn('risk_level', [AmlRiskLevel::High->value, AmlRiskLevel::Critical->value])
                        ->whereNotIn('status', [
                            ComplianceCaseStatus::Resolved->value,
                            ComplianceCaseStatus::Closed->value,
                        ]))
                    ->toggle(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\ActionGroup::make(ComplianceCaseActions::forTable())
                    ->label('Move')
                    ->icon('heroicon-m-arrow-path')
                    ->button()
                    ->outlined()
                    ->visible(fn (): bool => AdminAccess::permits(AdminPermission::AML_REVIEW)),
            ])
            ->bulkActions([])
            ->emptyStateHeading('No compliance cases')
            ->emptyStateDescription('Cases are opened by the AML screening lanes; none exist yet.');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('File')
                ->schema([
                    Infolists\Components\TextEntry::make('case_key')->label('Case')->copyable(),
                    Infolists\Components\TextEntry::make('case_type')->label('Type')->badge(),
                    Infolists\Components\TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (ComplianceCaseStatus $state): string => ucfirst($state->value))
                        ->color(fn (ComplianceCaseStatus $state): string => static::statusColor($state)),
                    Infolists\Components\TextEntry::make('risk_level')
                        ->label('Risk')
                        ->badge()
                        ->formatStateUsing(fn (AmlRiskLevel $state): string => ucfirst($state->value))
                        ->color(fn (AmlRiskLevel $state): string => static::riskColor($state)),
                    Infolists\Components\TextEntry::make('subject.username')->label('Subject')->placeholder('—'),
                    Infolists\Components\TextEntry::make('assigned_desk')->label('Desk'),
                ])
                ->columns(3),

            Infolists\Components\Section::make('Evidence')
                ->description('Fingerprints, not documents: the file references the evidence the lane sealed, and the evidence itself is not rendered here.')
                ->schema([
                    Infolists\Components\TextEntry::make('trigger_reference')->label('Trigger reference')->placeholder('—'),
                    Infolists\Components\TextEntry::make('evidence_fingerprint')
                        ->label('Evidence fingerprint')
                        ->copyable()
                        ->placeholder('—'),
                ])
                ->columns(2),

            Infolists\Components\Section::make('Timeline')
                ->schema([
                    Infolists\Components\TextEntry::make('created_at')
                        ->label('Opened')
                        ->state(fn (ComplianceCase $record): string => AdminFormat::marketTime($record->created_at)),
                    Infolists\Components\TextEntry::make('investigating_since')
                        ->label('Investigating since')
                        ->state(fn (ComplianceCase $record): string => AdminFormat::marketTime($record->investigating_since)),
                    Infolists\Components\TextEntry::make('escalated_at')
                        ->label('Escalated at')
                        ->state(fn (ComplianceCase $record): string => AdminFormat::marketTime($record->escalated_at)),
                    Infolists\Components\TextEntry::make('escalation_reason')
                        ->label('Escalation reason')
                        ->placeholder('—')
                        ->columnSpanFull(),
                    Infolists\Components\TextEntry::make('resolved_at')
                        ->label('Resolved at')
                        ->state(fn (ComplianceCase $record): string => AdminFormat::marketTime($record->resolved_at)),
                    Infolists\Components\TextEntry::make('closed_at')
                        ->label('Closed at')
                        ->state(fn (ComplianceCase $record): string => AdminFormat::marketTime($record->closed_at)),
                    Infolists\Components\TextEntry::make('resolution_note')
                        ->label('Resolution')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->columns(3),

            Infolists\Components\Section::make('Controls applied')
                ->description('Holds, restrictions and releases attached to this file. A hold that is still in force must be released before the file can be closed.')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('actions')
                        ->hiddenLabel()
                        ->schema([
                            Infolists\Components\TextEntry::make('action_type')
                                ->label('Action')
                                ->formatStateUsing(fn ($state): string => is_object($state) && property_exists($state, 'value') ? (string) $state->value : (string) $state),
                            Infolists\Components\TextEntry::make('reason_code')->label('Reason'),
                            Infolists\Components\TextEntry::make('is_released')
                                ->label('Released')
                                ->state(fn ($record): string => (bool) ($record->is_released ?? false) ? 'yes' : 'no'),
                            Infolists\Components\TextEntry::make('applied_at')
                                ->label('Applied')
                                ->state(fn ($record): string => AdminFormat::marketTime($record->applied_at ?? null)),
                        ])
                        ->columns(4)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function riskColor(AmlRiskLevel $level): string
    {
        return match ($level) {
            AmlRiskLevel::Low => 'success',
            AmlRiskLevel::Medium => 'warning',
            AmlRiskLevel::High => 'danger',
            AmlRiskLevel::Critical => 'danger',
        };
    }

    public static function statusColor(ComplianceCaseStatus $status): string
    {
        return match ($status) {
            ComplianceCaseStatus::Open => 'danger',
            ComplianceCaseStatus::Investigating => 'warning',
            ComplianceCaseStatus::Escalated => 'info',
            ComplianceCaseStatus::Resolved => 'success',
            ComplianceCaseStatus::Closed => 'gray',
        };
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
            'index' => Pages\ListComplianceCases::route('/'),
            'view' => Pages\ViewComplianceCase::route('/{record}'),
        ];
    }
}
