<?php

declare(strict_types=1);

namespace App\Filament\Resources\DataSubjectRequestResource\Pages;

use App\Filament\Resources\DataSubjectRequestResource;
use App\Models\DataSubjectRequest;
use Filament\Actions\Action;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

/**
 * The compliance queue.
 *
 * TAB ORDER FOLLOWS THE DEADLINE, because that is the only thing here with a
 * legal clock on it: overdue first (the promises already missed), then the open
 * ones still inside their window, then the closed history. A tab is a filter,
 * never a state: nothing on this screen moves a request by being displayed.
 *
 * NO CREATE ACTION. A data-subject request exists because a person asked for
 * one; a staff-authored row would have no verified address behind it.
 */
final class ListDataSubjectRequests extends ListRecords
{
    protected static string $resource = DataSubjectRequestResource::class;

    /**
     * @return array<int, Action>
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
            'overdue' => Tab::make((string) trans('privacy_requests.filament.filters.overdue'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->overdue())
                ->badge(fn (): int => DataSubjectRequest::query()->overdue()->count())
                ->badgeColor('danger'),

            'open' => Tab::make((string) trans('privacy_requests.filament.filters.open'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->open()),

            'all' => Tab::make('All'),

            'closed' => Tab::make('Closed')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [
                    'completed',
                    'rejected',
                    'cancelled',
                    'expired',
                ])),
        ];
    }
}
