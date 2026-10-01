<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PaymentTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public-Safe Payment Transaction API Resource.
 *
 * Serializes customer-facing payment transaction state without leaking provider secrets,
 * raw webhook payloads, internal cryptographic signatures, or sensitive database keys.
 *
 * @mixin PaymentTransaction
 */
class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PaymentTransaction $payment */
        $payment = $this->resource;

        return [
            'id' => (int) $payment->getKey(),
            'reference_id' => (string) $payment->reference_id,
            'amount' => (string) $payment->amount,
            'fee' => (string) ($payment->fee ?? '0.00'),
            'currency' => $payment->currency->value ?? 'THB',
            'channel' => $payment->channel->value ?? (string) $payment->channel,
            'direction' => $payment->direction->value ?? (string) $payment->direction,
            'status' => $payment->status->value ?? (string) $payment->status,
            'is_settled' => in_array($payment->status->value ?? (string) $payment->status, ['completed', 'settled', 'success'], true),
            'processed_at' => $payment->processed_at?->toIso8601String(),
            'created_at' => $payment->created_at?->toIso8601String(),
        ];
    }
}
