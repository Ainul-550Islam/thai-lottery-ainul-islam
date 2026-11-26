<?php

declare(strict_types=1);

namespace App\Filament\Resources\FraudAlertResource\Pages;

use App\Filament\Resources\FraudAlertResource;
use App\Models\FraudAlert;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

/**
 * The fraud work queue.
 *
 * The default tab is "awaiting adjudication" when anything is waiting, because an unreviewed
 * alert is a decision the platform has not taken about somebody's money. When the queue is
 * empty the screen falls back to everything so it never looks broken.
 *
 * There are no header actions: alerts are raised by the detection lanes, not typed in.
 */
class ListFraudAlerts extends ListRecords
{
    protected static string $resource = FraudAlertResource::class;

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
            'open' => Tab::make('Open')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', 'OPEN'))
                ->badge(fn (): int => FraudAlert::query()->where('status', 'OPEN')->count())
                ->badgeColor('danger'),

            'high_risk' => Tab::make('Risk 70+')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('risk_score', '>=', 70)
                    ->whereIn('status', ['OPEN', 'UNDER_REVIEW']))
                ->badge(fn (): int => FraudAlert::query()
                    ->where('risk_score', '>=', 70)
                    ->whereIn('status', ['OPEN', 'UNDER_REVIEW'])
                    ->count())
                ->badgeColor('warning'),

            'resolved' => Tab::make('Adjudicated')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', 'like', 'RESOLVED_%')),

            'all' => Tab::make('All'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return FraudAlert::query()->whereIn('status', ['OPEN', 'UNDER_REVIEW'])->exists() ? 'open' : 'all';
    }
}
