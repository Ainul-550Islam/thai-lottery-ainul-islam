<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentMethodConfigResource\Pages;

use App\Enums\PaymentMethodStatus;
use App\Filament\Resources\PaymentMethodConfigResource;
use App\Models\PaymentMethodConfig;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminPermission;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

/**
 * The offerable menu, as the registry sees it.
 *
 * Tab order answers the operational question in the order it is asked: what can carry traffic
 * right now, what is configured but dark, what has been withdrawn, and what is gone for good.
 * A tab is a filter — nothing here moves an offer.
 */
class ListPaymentMethodConfigs extends ListRecords
{
    protected static string $resource = PaymentMethodConfigResource::class;

    /**
     * @return array<int, Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        if (! AdminAccess::permits(AdminPermission::PROVIDERS_MANAGE)) {
            return [];
        }

        return [
            Actions\CreateAction::make()->label('New method offer'),
        ];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'enabled' => Tab::make('Offered')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', PaymentMethodStatus::Enabled->value))
                ->badge(fn (): int => PaymentMethodConfig::query()->where('status', PaymentMethodStatus::Enabled->value)->count())
                ->badgeColor('success'),

            'pending' => Tab::make('Configured, not offered')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', PaymentMethodStatus::Pending->value))
                ->badge(fn (): int => PaymentMethodConfig::query()->where('status', PaymentMethodStatus::Pending->value)->count())
                ->badgeColor('warning'),

            'disabled' => Tab::make('Withdrawn')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', PaymentMethodStatus::Disabled->value)),

            'all' => Tab::make('All'),

            'retired' => Tab::make('Retired')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', PaymentMethodStatus::Retired->value)),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'enabled';
    }
}
