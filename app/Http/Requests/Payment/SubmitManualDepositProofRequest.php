<?php

declare(strict_types=1);

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sender number and TrxID for a manual bKash/Nagad deposit. Every other
 * financial field is prohibited: the deposit's amount, method, wallet and
 * status are decided by the server and can never be restated by the client.
 */
final class SubmitManualDepositProofRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'sender_number' => ['required', 'string', 'max:20'],
            'trx_id' => ['required', 'string', 'max:32'],
            'user_id' => ['prohibited'],
            'wallet_id' => ['prohibited'],
            'deposit_id' => ['prohibited'],
            'amount' => ['prohibited'],
            'method' => ['prohibited'],
            'currency' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }
}
