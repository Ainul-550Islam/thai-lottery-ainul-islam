<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\Payment\PayoutTransferAccess;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class StorePayoutApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(PayoutTransferAccess::class)->authorize($this->user(), PayoutTransferAccess::REQUEST);

        return true;
    }

    public function rules(): array
    {
        return ['payout_id' => ['required', 'integer', 'min:1'], 'method' => ['required', 'in:wallet,bank_transfer,cheque'],
            'beneficiary' => ['prohibited_if:method,wallet', 'required_unless:method,wallet', 'array'],
            'beneficiary.*' => ['required', 'string', 'max:500'], 'note' => ['required', 'string', 'min:15', 'max:1000'],
            'amount' => ['prohibited'], 'currency' => ['prohibited'], 'beneficiary_user_id' => ['prohibited'],
            'requester_user_id' => ['prohibited'], 'request_key' => ['prohibited'], 'facts_hash' => ['prohibited'], 'batch_key' => ['prohibited']];
    }

    protected function failedValidation(Validator $validator): void
    {
        // Never flash bank/payee destination fields into the browser session on failure.
        throw new HttpResponseException($this->expectsJson()
            ? response()->json(['code' => 'payout_intake_validation_failed', 'fields' => array_keys($validator->errors()->messages())], 422)->header('Cache-Control', 'private, no-store')
            : redirect()->back()->withErrors(['intake' => 'Invalid payout intake. Re-enter sensitive fields; nothing was saved.']));
    }
}
