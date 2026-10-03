<?php

declare(strict_types=1);

namespace App\Http\Requests\Verification;

use App\Enums\VerificationDocumentType;
use App\Rules\DocumentUploadRule;
use Illuminate\Foundation\Http\FormRequest;

/*
 * PROMPT 3 — secure verification submission validation.
 *
 * Country code (controlled catalogue), mobile (structured pair),
 * document type (configured enum values), front document (required)
 * and back document (per configured policy) — each upload runs the
 * hardened DocumentUploadRule (content MIME, size, dimensions,
 * corruption, executable/traversal name tricks).
 *
 * Client-supplied status / approved / phone_verified / user_id keys
 * are not in the rules and are never read anywhere downstream.
 */
final class SubmitAccountVerificationRequest extends FormRequest
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
            // Format + pair policy is enforced by the service (the
            // established 'document' error surface this page asserts).
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

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'country_code' => __('account_services.verification_country_code'),
            'mobile' => __('account_services.verification_mobile'),
            'document_type' => __('account_services.verification_document_type'),
            'document' => __('account_services.verification_document_front'),
            'document_back' => __('account_services.verification_document_back'),
        ];
    }
}
