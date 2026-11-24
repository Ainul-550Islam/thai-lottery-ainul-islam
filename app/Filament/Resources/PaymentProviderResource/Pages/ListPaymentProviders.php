<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentProviderResource\Pages;

use App\Enums\PaymentProviderStatus;
use App\Filament\Resources\PaymentProviderResource;
use App\Models\PaymentProvider;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

/**
 * The provider registry as a work queue.
 *
 * Tabs answer the operational question rather than offering a neutral list: "which
 * providers are carrying traffic?", "which are out?", "which are switched on but not
 * ready?". The last one is the tab that earns this screen — a provider stuck at Pending
 * while holding the only route for a currency is invisible in a plain table.
 */
class ListPaymentProviders extends ListRecords
{
    protected static string $resource = PaymentProviderResource::class;

    /**
     * @return array<int, Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        // No create action: a provider row without a driver class is a false promise.
        return [];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'serving' => Tab::make('Serving traffic')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', PaymentProviderStatus::Active))
                ->badge(fn (): int => PaymentProvider::query()->where('status', PaymentProviderStatus::Active)->count())
                ->badgeColor('success'),

            'out' => Tab::make('Out of traffic')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [
                    PaymentProviderStatus::Suspended,
                    PaymentProviderStatus::Disabled,
                ]))
                ->badge(fn (): int => PaymentProvider::query()->whereIn('status', [
                    PaymentProviderStatus::Suspended,
                    PaymentProviderStatus::Disabled,
                ])->count())
                ->badgeColor('warning'),

            'pending' => Tab::make('Never activated')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', PaymentProviderStatus::Pending)),

            'all' => Tab::make('All'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'all';
    }
}
