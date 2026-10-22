<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\Payment\PayoutTransferAccess;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class ReviewPayoutTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(PayoutTransferAccess::class)->authorize($this->user(), PayoutTransferAccess::REVIEW);

        return true;
    }

    public function rules(): array
    {
        return ['outcome' => ['required', 'in:completed,failed'], 'note' => ['required', 'string', 'min:15', 'max:1000'],
            'evidence_verified' => ['required', 'accepted'], 'evidence_id' => ['required', 'integer', 'min:1'],
            'provider_reference' => ['required_if:outcome,completed', 'prohibited_if:outcome,failed', 'nullable', 'string', 'max:255']];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException($this->expectsJson()
            ? response()->json(['code' => 'private_review_validation_failed'], 422)->header('Cache-Control', 'private, no-store')
            : redirect()->back()->withErrors(['review' => 'Invalid final review. Re-enter sensitive reference fields; nothing was retained.']));
    }
}
