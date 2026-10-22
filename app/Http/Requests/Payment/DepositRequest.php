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
            // Fee figures are ignored, not rejected. This is the house rule the fee-preview lane
            // already documents (a_client_supplied_fee_figure_is_ignored_not_validated): a smuggled
            // fee must not be answered with a 422 as an unexpected key, and it must not change the
            // number the endpoint records - the answer is the service's computation whatever else
            // arrives in the body. The guard below stays intact: identity, ownership, settlement
            // state, provider evidence and balance remain things a caller may never name.
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
