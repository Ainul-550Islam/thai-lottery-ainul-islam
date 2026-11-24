<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\AmlRiskLevel;
use App\Filament\Resources\AmlRiskAssessmentResource\Pages;
use App\Models\AmlRiskAssessment;
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
 * The AML assessment store — what the platform measured, and when it stopped being true.
 *
 * WHY IT IS READ-ONLY
 *
 * An assessment is not an opinion an operator holds; it is the output of
 * AmlRiskAssessmentService::measure(), a deterministic function of wallet, transaction and
 * account facts, sealed with an evidence fingerprint. The row is then immutable: measuring
 * again supersedes the old row (`status = superseded`, `superseded_at`) rather than editing
 * it. A screen with an edit form would be a screen where somebody could change the score that
 * justified a hold — so there is no create, no edit, no delete, and no action of any kind.
 *
 * WHY THE SUPERSEDED ROWS ARE STILL HERE
 *
 * The audit question is almost never "what is the risk today" — the assessment lane answers
 * that. It is "what did we believe on the day we froze the account, and on what evidence". A
 * superseded row answers that; deleting it is how that question becomes unanswerable.
 *
 * `isHighRisk()` and `isProhibited()` on AmlRiskService read these rows, so what an operator
 * sees here is the same evidence the withdrawal gate uses — not a parallel calculation.
 *
 * PERMISSION: 'aml.view'.
 */
class AmlRiskAssessmentResource extends Resource
{
    protected static ?string $model = AmlRiskAssessment::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static ?string $navigationGroup = 'Compliance';

    protected static ?string $navigationLabel = 'AML risk store';

    protected static ?string $modelLabel = 'risk assessment';

    protected static ?string $pluralModelLabel = 'risk assessments';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'assessment_key';

    public static function canViewAny(): bool
    {
        return AdminAccess::permitsAny([
            AdminPermission::AML_VIEW,
            AdminPermission::AML_REVIEW,
        ]);
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof AmlRiskAssessment && static::canViewAny();
    }

    public static function canCreate(): bool
    {
        // Measurements come from the lane, never from a form.
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        // A superseded assessment is the evidence behind a past decision.
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('assessed_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user'))
            ->columns([
                Tables\Columns\TextColumn::make('assessment_key')
                    ->label('Assessment')
                    ->searchable()
                    ->copyable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('user.username')
                    ->label('Subject')
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('risk_level')
                    ->label('Level')
                    ->badge()
                    ->formatStateUsing(fn (AmlRiskLevel $state): string => $state->label())
                    ->color(fn (AmlRiskLevel $state): string => static::levelColor($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('score')
                    ->label('Score')
                    ->numeric()
                    ->sortable()
                    ->description('0–100, banded by AmlRiskLevel::fromScore()'),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === AmlRiskAssessment::STATUS_CURRENT ? 'Current' : 'Superseded')
                    ->color(fn (string $state): string => $state === AmlRiskAssessment::STATUS_CURRENT ? 'success' : 'gray'),

                Tables\Columns\TextColumn::make('assessment_version')
                    ->label('Version')
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('assessed_at')
                    ->label('Measured')
                    ->since()
                    ->tooltip(fn ($state): string => AdminFormat::marketTime($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('superseded_at')
                    ->label('Superseded')
                    ->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state))
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('risk_level')
                    ->label('Level')
                    ->options(fn (): array => collect(AmlRiskLevel::cases())
                        ->mapWithKeys(fn (AmlRiskLevel $level): array => [$level->value => $level->label()])
                        ->all()),

                Tables\Filters\Filter::make('current_only')
                    ->label('Current assessments only')
                    ->query(fn (Builder $query): Builder => $query->where('status', AmlRiskAssessment::STATUS_CURRENT))
                    ->default(),

                Tables\Filters\Filter::make('elevated')
                    ->label('High or critical')
                    ->query(fn (Builder $query): Builder => $query->whereIn('risk_level', [
                        AmlRiskLevel::High->value,
                        AmlRiskLevel::Critical->value,
                    ]))
                    ->toggle(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([])
            ->emptyStateHeading('No assessments')
            ->emptyStateDescription('The assessment lane writes here when it measures an account; nothing has been measured yet.');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Measurement')
                ->schema([
                    Infolists\Components\TextEntry::make('assessment_key')->label('Assessment')->copyable(),
                    Infolists\Components\TextEntry::make('user.username')->label('Subject')->placeholder('—'),
                    Infolists\Components\TextEntry::make('risk_level')
                        ->label('Level')
                        ->badge()
                        ->formatStateUsing(fn (AmlRiskLevel $state): string => $state->label())
                        ->color(fn (AmlRiskLevel $state): string => static::levelColor($state)),
                    Infolists\Components\TextEntry::make('score')->numeric(),
                    Infolists\Components\TextEntry::make('assessment_version')->label('Version')->badge(),
                    Infolists\Components\TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => $state === AmlRiskAssessment::STATUS_CURRENT ? 'Current' : 'Superseded')
                        ->color(fn (string $state): string => $state === AmlRiskAssessment::STATUS_CURRENT ? 'success' : 'gray'),
                ])
                ->columns(3),

            Infolists\Components\Section::make('Why this score')
                ->description('The reason codes the measurement produced. They are the whole justification: the score alone is not an argument.')
                ->schema([
                    Infolists\Components\TextEntry::make('reason_codes')
                        ->hiddenLabel()
                        ->badge()
                        ->placeholder('No reason codes recorded')
                        ->columnSpanFull(),
                ]),

            Infolists\Components\Section::make('Evidence')
                ->description('The fingerprint the assessment was sealed against. assertCurrent() refuses a hold whose claimed fingerprint no longer matches.')
                ->schema([
                    Infolists\Components\TextEntry::make('evidence_fingerprint')
                        ->label('Fingerprint')
                        ->copyable(),
                    Infolists\Components\TextEntry::make('assessed_at')
                        ->label('Measured at')
                        ->state(fn (AmlRiskAssessment $record): string => AdminFormat::marketTime($record->assessed_at)),
                    Infolists\Components\TextEntry::make('superseded_at')
                        ->label('Superseded at')
                        ->state(fn (AmlRiskAssessment $record): string => AdminFormat::marketTime($record->superseded_at)),
                ])
                ->columns(3),
        ]);
    }

    public static function levelColor(AmlRiskLevel $level): string
    {
        return match ($level) {
            AmlRiskLevel::Low => 'success',
            AmlRiskLevel::Medium => 'warning',
            AmlRiskLevel::High => 'danger',
            AmlRiskLevel::Critical => 'danger',
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
            'index' => Pages\ListAmlRiskAssessments::route('/'),
            'view' => Pages\ViewAmlRiskAssessment::route('/{record}'),
        ];
    }
}
