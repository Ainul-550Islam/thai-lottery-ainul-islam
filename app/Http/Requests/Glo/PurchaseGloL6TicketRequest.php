<?php

declare(strict_types=1);

namespace App\Http\Requests\Glo;

use Illuminate\Foundation\Http\FormRequest;

class PurchaseGloL6TicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->isActive();
    }

    protected function prepareForValidation(): void
    {
        $headerKey = $this->header('Idempotency-Key') ?? $this->header('X-Idempotency-Key');

        if (! $this->filled('idempotency_key') && is_string($headerKey) && trim($headerKey) !== '') {
            $this->merge([
                'idempotency_key' => trim($headerKey),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'min:4', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],

            // Explicit anti-mass-assignment / anti-tampering rejection:
            'user_id' => ['prohibited'],
            'owner_user_id' => ['prohibited'],
            'wallet_id' => ['prohibited'],
            'status' => ['prohibited'],
            'price' => ['prohibited'],
            'unit_price' => ['prohibited'],
            'financial_transaction_id' => ['prohibited'],
        ];
    }
}
