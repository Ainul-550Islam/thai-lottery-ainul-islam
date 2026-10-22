<?php

declare(strict_types=1);

namespace App\Http\Requests\Legal;

use Illuminate\Foundation\Http\FormRequest;

/** Validates explicit acceptance/acknowledgement of the current public documents. */
final class LegalConsentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'terms' => ['required', 'accepted'],
            'privacy_notice' => ['required', 'accepted'],
            'terms_version' => ['required', 'string', 'min:1', 'max:64'],
            'terms_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i'],
            'privacy_version' => ['required', 'string', 'min:1', 'max:64'],
            'privacy_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i']
        ];
    }
}
