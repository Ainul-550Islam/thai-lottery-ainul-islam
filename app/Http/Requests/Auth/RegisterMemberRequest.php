<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Rules\StrongPasswordRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/*
 * PROMPT 3 — complete member registration validation.
 *
 * The benchmark field set, mapped onto the real schema:
 *   referral_id  -> agents.agent_code (resolved server-side later)
 *   mobile       -> users.phone (minimum six digits, unique)
 *   email        -> users.email (lowercased, unique, case-insensitive)
 *   date_of_birth-> users.date_of_birth
 *   gender/city/country/nationality -> the PROMPT 3 columns
 *   terms        -> timestamped acceptance (preferences), never a
 *                   hidden-input trust
 *
 * The request NEVER accepts a client-chosen username, status, role,
 * wallet or balance — those are server-derived inside the service.
 */
final class RegisterMemberRequest extends FormRequest
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
        $minAgeYears = 18;

        return [
            'referral_id' => ['required', 'string', 'min:3', 'max:64'],

            'mobile' => [
                'required',
                'string',
                // Minimum SIX DIGITS per the public registration
                // specification (audit finding 9). The lookahead counts
                // digits through the separators the format still allows,
                // so "081-23" (six characters, five digits) is refused.
                'min:6',
                'max:20',
                'regex:/^(?=(?:\D*\d){6})[0-9][0-9\s\-().]{5,24}$/',
                Rule::unique('users', 'phone'),
            ],

            'password' => ['required', 'string', 'max:255', new StrongPasswordRule],
            'password_confirmation' => ['required', 'string', 'same:password'],

            'first_name' => ['required', 'string', 'min:1', 'max:100', 'regex:/^[\p{L}\p{M}\'.\- ]+$/u'],
            'last_name' => ['required', 'string', 'min:1', 'max:100', 'regex:/^[\p{L}\p{M}\'.\- ]+$/u'],

            'gender' => ['required', 'string', Rule::in(['male', 'female', 'unspecified'])],

            'city' => ['required', 'string', 'min:2', 'max:100', 'regex:/^[\p{L}\p{M}\'.\- ]+$/u'],
            'country' => ['required', 'string', 'min:2', 'max:100', 'regex:/^[\p{L}\p{M}\'.\- ]+$/u'],
            'nationality' => ['required', 'string', 'min:2', 'max:100', 'regex:/^[\p{L}\p{M}\'.\- ]+$/u'],

            'email' => [
                'required',
                'string',
                'email:filter',
                'max:255',
                Rule::unique('users', 'email'),
            ],

            'date_of_birth' => [
                'required',
                'date',
                'after:1900-01-01',
                'before:'.now()->subYears($minAgeYears)->toDateString(),
            ],

            // Optional identity fields (competitor parity C16). Blank is allowed.
            'national_id' => [
                'nullable',
                'string',
                'max:40',
                'regex:/^[A-Za-z0-9\s\-]+$/',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $normalised = \App\Support\NationalIdentity::normalise(is_string($value) ? $value : null);
                    if ($normalised !== null && ! \App\Support\NationalIdentity::isValid($normalised)) {
                        $fail(__('public_pages.register_error_national_id_format'));
                    }
                },
            ],
            'wechat_id' => [
                'nullable',
                'string',
                'min:6',
                'max:20',
                'regex:/^[A-Za-z][A-Za-z0-9_\-]{5,19}$/',
            ],

            'terms' => ['required', 'accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'referral_id' => __('public_pages.register_referral'),
            'mobile' => __('public_pages.register_mobile'),
            'first_name' => __('public_pages.register_first_name'),
            'last_name' => __('public_pages.register_last_name'),
            'gender' => __('public_pages.register_gender'),
            'city' => __('public_pages.register_city'),
            'country' => __('public_pages.register_country'),
            'email' => __('public_pages.register_email'),
            'date_of_birth' => __('public_pages.register_dob'),
            'nationality' => __('public_pages.register_nationality'),
            'national_id' => __('public_pages.register_national_id'),
            'wechat_id' => __('public_pages.register_wechat_id'),
            'terms' => __('public_pages.register_terms'),
        ];
    }
}
