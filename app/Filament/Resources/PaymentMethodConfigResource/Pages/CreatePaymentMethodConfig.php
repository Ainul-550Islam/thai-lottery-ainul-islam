<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentMethodConfigResource\Pages;

use App\DTOs\Payment\PaymentMethodData;
use App\Enums\PaymentMethodStatus;
use App\Filament\Resources\PaymentMethodConfigResource;
use App\Models\PaymentMethodConfig;
use App\Services\Payment\PaymentProviderRegistry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Registering an offer — the one write in this resource, and it is a service call.
 *
 * THE FORM DOES NOT CREATE A MODEL
 *
 * `handleRecordCreation()` hands the input to `PaymentProviderRegistry::registerMethod()`
 * through `PaymentMethodData::fromInput()`, which is where the offer's identity is defined: a
 * deterministic `method_key` over (provider, method, currency), decimal-string money (never a
 * float), min ≤ max, fee in [0, 10000], and a status word from the enum.
 *
 * THREE ANSWERS, ALL REPORTED AS THEMSELVES
 *
 *   - registered  → the row exists, Pending or Enabled as chosen.
 *   - replayed    → the exact same offer already exists (same provider, same min and max).
 *                   That is not an error and not a new row: the operator is told it was
 *                   already there and sent to it.
 *   - refused     → a malformed field, or the same method key with DIFFERENT limits
 *                   (`duplicate`). The registry's wording is shown, the form stays open, and
 *                   nothing is written. A silent "saved" here would be the worst outcome: the
 *                   operator would believe the menu changed when it did not.
 *
 * WHY RETIRED IS NOT AN OPTION ON THIS FORM
 *
 * A method born retired would be a row that cannot be offered and cannot be moved — the enum
 * has no transitions out of Retired. Retirement is a decision taken later, on an offer that
 * once worked, and it stays a two-step move (Disable, then Retire).
 */
class CreatePaymentMethodConfig extends CreateRecord
{
    protected static string $resource = PaymentMethodConfigResource::class;

    public function getTitle(): string
    {
        return 'New method offer';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            $payload = PaymentMethodData::fromInput(
                providerCode: (string) $data['provider_code'],
                methodCode: (string) $data['method_code'],
                currency: (string) $data['currency'],
                minAmount: (string) $data['min_amount'],
                maxAmount: (string) $data['max_amount'],
                feeBps: (int) ($data['fee_bps'] ?? 0),
                status: (string) $data['status'],
            );

            $result = app(PaymentProviderRegistry::class)->registerMethod($payload);
        } catch (Throwable $e) {
            // The registry speaks in the domain's own words ("duplicate", "malformed",
            // "provider not found"); relay them and write nothing.
            Notification::make()
                ->danger()
                ->title('The registry refused this offer')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            // `halt()` is declared `: void` and throws, so the analyser (and a reader) cannot see
            // that nothing below runs. Throwing the same exception says it in the type system.
            throw new Halt();
        }

        if ($result['replayed']) {
            Notification::make()
                ->warning()
                ->title('This offer already exists')
                ->body('The same provider, method, currency, minimum and maximum were already registered. Nothing new was written — opening the existing offer instead.')
                ->persistent()
                ->send();
        } else {
            Notification::make()
                ->success()
                ->title('Offer registered')
                ->body(sprintf('Status is %s. Move it to Enabled when it should carry traffic.', $result['method']->status->value))
                ->send();
        }

        return $result['method'];
    }

    protected function getRedirectUrl(): string
    {
        /** @var PaymentMethodConfig $record */
        $record = $this->getRecord();

        return PaymentMethodConfigResource::getUrl('view', ['record' => $record]);
    }
}
