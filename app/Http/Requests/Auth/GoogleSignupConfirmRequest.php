<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The ONLY things a Google sign-up asks the member for. Everything else is from the id_token.
 * Rules mirror RegisterMemberRequest so both entrances enforce the same policy.
 */
final class GoogleSignupConfirmRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $minAge = min(255, max(18, (int) config('legal.minimum_age', 18)));

        return [
            'referral_id' => ['required', 'string', 'min:3', 'max:64'],
            'date_of_birth' => ['required', 'date', 'after:1900-01-01', 'before_or_equal:'.now()->subYears($minAge)->toDateString()],
            'age_attestation' => ['required', 'accepted'],
            'terms' => ['required', 'accepted'],
            'privacy_notice' => ['required', 'accepted'],
            'terms_version' => ['required', 'string', 'min:1', 'max:64'],
            'terms_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i'],
            'privacy_version' => ['required', 'string', 'min:1', 'max:64'],
            'privacy_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i'],
        ];
    }
}
