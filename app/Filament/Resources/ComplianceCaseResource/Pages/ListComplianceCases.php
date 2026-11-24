<?php

declare(strict_types=1);

namespace App\Filament\Resources\ComplianceCaseResource\Pages;

use App\Enums\ComplianceCaseStatus;
use App\Filament\Resources\ComplianceCaseResource;
use App\Models\ComplianceCase;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

/**
 * The AML desk queue.
 *
 * Tab order follows the work: live files first (unworked intake, then files a desk owns, then
 * the escalated ones that outrank a desk's own authority), because an escalated file is the
 * only one here with a regulatory clock attached.
 *
 * A tab is a filter, never a state. Nothing on this screen moves a case by being displayed;
 * every move is a service call from ComplianceCaseResource::lifecycleActions().
 *
 * NO CREATE ACTION. Cases open from the assessment lanes with an evidence fingerprint behind
 * them; a hand-typed case would carry no evidence.
 */
class ListComplianceCases extends ListRecords
{
    protected static string $resource = ComplianceCaseResource::class;

    /**
     * @return array<int, Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'intake' => Tab::make('Intake')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', ComplianceCaseStatus::Open->value))
                ->badge(fn (): int => ComplianceCase::query()->where('status', ComplianceCaseStatus::Open->value)->count())
                ->badgeColor('danger'),

            'investigating' => Tab::make('Investigating')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', ComplianceCaseStatus::Investigating->value))
                ->badge(fn (): int => ComplianceCase::query()->where('status', ComplianceCaseStatus::Investigating->value)->count())
                ->badgeColor('warning'),

            'escalated' => Tab::make('Escalated')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', ComplianceCaseStatus::Escalated->value))
                ->badge(fn (): int => ComplianceCase::query()->where('status', ComplianceCaseStatus::Escalated->value)->count())
                ->badgeColor('info'),

            'live' => Tab::make('Live')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [
                    ComplianceCaseStatus::Open->value,
                    ComplianceCaseStatus::Investigating->value,
                    ComplianceCaseStatus::Escalated->value,
                ])),

            'closed' => Tab::make('History')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [
                    ComplianceCaseStatus::Resolved->value,
                    ComplianceCaseStatus::Closed->value,
                ])),

            'all' => Tab::make('All'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return ComplianceCase::query()
            ->whereIn('status', [
                ComplianceCaseStatus::Open->value,
                ComplianceCaseStatus::Investigating->value,
                ComplianceCaseStatus::Escalated->value,
            ])
            ->exists() ? 'live' : 'all';
    }
}
