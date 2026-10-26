<?php

declare(strict_types=1);

namespace App\Http\Requests\Payment;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Canonical authenticated deposit-initiation contract.
 *
 * THE METHOD IS AN ENUM VALUE, NOT A RAIL NAME
 * --------------------------------------------
 * `method` accepts only App\Enums\PaymentMethod values, and that enum
 * deliberately has no `thai_bank` case: the Thai bank payout rail is chosen
 * by WithdrawalDisbursementService from server-side configuration and the
 * stored destination, never by a caller. `not_in:thai_bank` states that
 * explicitly so the day someone adds a case to the enum, or a client learns
 * the internal key from a log line, the request is still refused with a
 * message that names the reason instead of a generic "invalid method".
 */
final class DepositRequest extends FormRequest
{
    private bool $idempotencyConflict = false;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $body = $this->input('idempotency_key');
        $headerLegacy = $this->header('X-Idempotency-Key');
        $headerStd = $this->header('Idempotency-Key');

        $values = [];
        foreach ([$body, $headerLegacy, $headerStd] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                $values[] = trim($candidate);
            }
        }

        $unique = array_values(array_unique($values));
        if (count($unique) > 1) {
            $this->idempotencyConflict = true;
        }

        if ($body === null && $unique !== []) {
            $this->merge(['idempotency_key' => $unique[0]]);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'string', 'regex:/^\d+(\.\d{1,2})?$/'],
            'method' => ['required', 'string', 'not_in:thai_bank', 'in:'.implode(',', array_column(PaymentMethod::cases(), 'value'))],
            'currency' => ['nullable', 'string', 'in:THB,USD,BDT'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'user_id' => ['prohibited'],
            'wallet_id' => ['prohibited'],
            'status' => ['prohibited'],
            'balance' => ['prohibited'],
            'approved_by' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->idempotencyConflict) {
                $validator->errors()->add('idempotency_key', 'Conflicting idempotency keys were supplied.');
            }
        });
    }

    public function amount(): string
    {
        return (string) $this->validated('amount');
    }
}
