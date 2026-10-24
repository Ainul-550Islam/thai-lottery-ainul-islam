<?php

declare(strict_types=1);

namespace App\Http\Requests\Glo;

use Illuminate\Foundation\Http\FormRequest;

class ReserveGloL6TicketRequest extends FormRequest
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

        if ($this->has('ticket_number') && is_string($this->input('ticket_number'))) {
            $this->merge([
                'ticket_number' => trim($this->input('ticket_number')),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'draw_id' => ['required', 'integer', 'min:1'],
            'ticket_number' => ['required', 'string', 'size:6', 'regex:/^\d{6}$/'],
            'set_series' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'idempotency_key' => ['required', 'string', 'min:4', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'expected_unit_price' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],

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

    public function resolvedSeries(): int
    {
        return (int) ($this->validated('set_series') ?? 1);
    }
}
