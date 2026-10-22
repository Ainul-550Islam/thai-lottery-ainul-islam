<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\Payment\PayoutTransferAccess;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class UploadPayoutEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(PayoutTransferAccess::class)->authorize($this->user(), PayoutTransferAccess::UPLOAD_EVIDENCE);

        return true;
    }

    public function rules(): array
    {
        return ['evidence' => ['required', 'file', 'max:2048'], 'note' => ['required', 'string', 'min:15', 'max:1000'],
            'url' => ['prohibited'], 'disk' => ['prohibited'], 'path' => ['prohibited'], 'sha256' => ['prohibited'], 'uploaded_by' => ['prohibited']];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException($this->expectsJson()
            ? response()->json(['code' => 'private_evidence_validation_failed'], 422)->header('Cache-Control', 'private, no-store')
            : redirect()->back()->withErrors(['evidence' => 'Invalid private file or rationale. Sensitive input was not retained.']));
    }
}
