<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentProviderResource\Pages;

use App\Filament\Resources\PaymentProviderResource;
use App\Models\PaymentProvider;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

/**
 * One provider, in full.
 *
 * The header actions are the same lifecycle verbs as the table, re-used rather than
 * re-implemented, so there is exactly one path from a click to
 * PaymentProviderRegistry::transitionProvider().
 */
class ViewPaymentProvider extends ViewRecord
{
    protected static string $resource = PaymentProviderResource::class;

    /**
     * @return array<int, Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return PaymentProviderResource::headerLifecycleActions();
    }

    protected function resolveRecord(int|string $key): PaymentProvider
    {
        /** @var PaymentProvider $provider */
        $provider = parent::resolveRecord($key);

        return $provider;
    }
}
