<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use App\Enums\VerificationDocumentType;
use App\Rules\DocumentUploadRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates player identity verification submissions.
 */
class AccountVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxKb = (int) config('account_verification.uploads.max_file_kb', 10240);
        $requireBack = (bool) config('account_verification.uploads.require_back_document', false);
        $countryCodes = implode(',', (array) config('account_verification.phone.country_codes', ['+66']));
        $documentTypes = VerificationDocumentType::configuredValuesForValidation();

        return [
            'country_code' => ['nullable', 'string', 'max:8', 'in:'.$countryCodes],
            'mobile' => ['nullable', 'string', 'max:20'],
            'document_type' => ['required', 'string', 'in:'.$documentTypes],
            'document_number' => ['nullable', 'string', 'max:100'],
            'document' => [
                'required',
                'file',
                'max:'.$maxKb,
                new DocumentUploadRule,
            ],
            'document_back' => [
                $requireBack ? 'required' : 'nullable',
                'file',
                'max:'.$maxKb,
                new DocumentUploadRule,
            ],
        ];
    }
}
