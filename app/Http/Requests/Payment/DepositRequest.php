<?php

declare(strict_types=1);

namespace App\Http\Requests\Payment;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Canonical authenticated deposit-initiation contract.
 *
 * The client may request an amount, currency, payment channel and
 * idempotency key. Identity, wallet ownership, fees, provider evidence and
 * terminal state are always derived by the server and cannot be asserted by
 * request data.
 */
final class DepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'string', 'regex:/^\d+(\.\d{1,2})?$/'],
            'method' => ['required', 'string', 'in:'.implode(',', array_column(PaymentMethod::cases(), 'value'))],
            'currency' => ['nullable', 'string', 'in:THB,USD,BDT'],
            'idempotency_key' => ['nullable', 'string', 'min:16', 'max:128', 'regex:/^[A-Za-z0-9:_\-.]+$/'],
            'user_id' => ['prohibited'],
            'wallet_id' => ['prohibited'],
            'status' => ['prohibited'],
            'fee' => ['prohibited'],
            'fee_amount' => ['prohibited'],
            'fee_percentage' => ['prohibited'],
            'net_amount' => ['prohibited'],
            'exchange_rate' => ['prohibited'],
            'provider' => ['prohibited'],
            'provider_reference' => ['prohibited'],
            'provider_verified' => ['prohibited'],
            'balance' => ['prohibited'],
            'approved_by' => ['prohibited'],
        ];
    }

    public function amount(): string
    {
        return (string) $this->validated('amount');
    }
}
