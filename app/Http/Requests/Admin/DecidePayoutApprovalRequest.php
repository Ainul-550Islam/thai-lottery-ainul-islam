<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\Payment\PayoutTransferAccess;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class DecidePayoutApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(PayoutTransferAccess::class)->authorize($this->user(), PayoutTransferAccess::APPROVE);

        return true;
    }

    public function rules(): array
    {
        return ['decision' => ['required', 'in:approved,rejected'], 'note' => ['required', 'string', 'min:15', 'max:1000'],
            'original_facts_verified' => ['required', 'accepted'], 'checker_user_id' => ['prohibited'],
            'amount' => ['prohibited'], 'currency' => ['prohibited'], 'beneficiary' => ['prohibited'], 'facts_hash' => ['prohibited']];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException($this->expectsJson()
            ? response()->json(['code' => 'payout_approval_validation_failed'], 422)->header('Cache-Control', 'private, no-store')
            : redirect()->back()->withErrors(['approval' => 'Invalid verdict. No sensitive input was retained.']));
    }
}
