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
 *
 * Two different refusal styles are used on purpose:
 *
 *  - Identity/state assertions (`user_id`, `wallet_id`, `status`,
 *    `provider`, ...) are PROHIBITED: a client trying to name another
 *    wallet or a terminal state is attempting something authorization-
 *    relevant, and the request is refused outright.
 *  - Money-computation echoes (`fee`, `fee_amount`, `fee_percentage`,
 *    `net_amount`, `exchange_rate`) are IGNORED, not prohibited: they are
 *    never read anywhere in the deposit engine — the fee is always
 *    computed server-side from configuration — so a client that echoes
 *    them back (as several payment UIs do) gets a working deposit with
 *    the server's numbers, not a validation error. Ignoring them costs
 *    nothing: an unread field cannot influence the computation.
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
            // 'fee', 'fee_amount', 'fee_percentage', 'net_amount' and
            // 'exchange_rate' are deliberately NOT declared here: undeclared
            // fields are ignored by validated(), and the deposit engine
            // never reads them from the request. See the class docblock.
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
