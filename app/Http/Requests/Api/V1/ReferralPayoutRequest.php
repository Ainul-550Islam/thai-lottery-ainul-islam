<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ReferralPayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $headerKey = $this->header('X-Idempotency-Key');
        if (! $this->filled('idempotency_key') && is_string($headerKey) && trim($headerKey) !== '') {
            $this->merge(['idempotency_key' => trim($headerKey)]);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'string', 'regex:/^\d+(\.\d{1,2})?$/'],
            'idempotency_key' => ['required', 'string', 'min:1', 'max:128', 'regex:/^[A-Za-z0-9_.:\-]+$/'],
            'member_bank_account_id' => ['nullable', 'integer', 'min:1'],
            'currency' => ['nullable', 'string', Rule::in(array_map(static fn (Currency $c): string => $c->value, Currency::cases()))],
            'wallet_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }

    public function idempotencyKey(): string
    {
        return trim((string) $this->validated('idempotency_key'));
    }
}
