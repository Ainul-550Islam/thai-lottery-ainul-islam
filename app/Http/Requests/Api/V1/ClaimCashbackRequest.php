<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class ClaimCashbackRequest extends FormRequest
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
            'idempotency_key' => ['required', 'string', 'min:1', 'max:128', 'regex:/^[A-Za-z0-9_.:\-]+$/'],
            'amount' => ['prohibited'],
            'user_id' => ['prohibited'],
            'member_id' => ['prohibited'],
            'wallet_id' => ['prohibited'],
        ];
    }

    public function idempotencyKey(): string
    {
        return trim((string) $this->validated('idempotency_key'));
    }
}
