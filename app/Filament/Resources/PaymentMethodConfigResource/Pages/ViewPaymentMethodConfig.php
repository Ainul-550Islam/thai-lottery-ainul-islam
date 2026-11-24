<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentMethodConfigResource\Pages;

use App\Filament\Resources\PaymentMethodConfigResource;
use App\Filament\Resources\PaymentMethodConfigResource\PaymentMethodConfigActions;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

/**
 * One offer, with the lifecycle verbs in the header.
 *
 * The verbs are the list's own declarations (`PaymentMethodConfigActions::forPage()`), so the
 * queue and the record can never offer different moves for the same state. There is no edit
 * form: the commercial terms are what a payer was shown and the registry treats a re-pledge
 * under the same key as a duplicate, not an update.
 */
class ViewPaymentMethodConfig extends ViewRecord
{
    protected static string $resource = PaymentMethodConfigResource::class;

    /**
     * @return array<int, Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return PaymentMethodConfigActions::forPage();
    }
}
