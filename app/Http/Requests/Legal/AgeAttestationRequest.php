<?php

declare(strict_types=1);

namespace App\Http\Requests\Legal;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/** Validates the self-declaration form without allowing a DOB overwrite. */
final class AgeAttestationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $user = $this->user();
        $dateOnFile = $user instanceof User && $user->date_of_birth !== null;

        return [
            'age_attestation' => ['required', 'accepted'],
            'date_of_birth' => [
                $dateOnFile ? 'nullable' : 'required',
                'date_format:Y-m-d',
                'after:1900-01-01',
                'before_or_equal:today',
            ],
        ];
    }
}
