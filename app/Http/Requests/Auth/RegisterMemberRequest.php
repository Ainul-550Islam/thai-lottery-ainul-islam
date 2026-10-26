<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Rules\StrongPasswordRule;
use App\Services\Auth\CaptchaService;
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
        $minAgeYears = min(255, max(18, (int) config('legal.minimum_age', 18)));

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
                'before_or_equal:'.now()->subYears($minAgeYears)->toDateString(),
            ],

            'wechat_id' => [
                'nullable',
                'string',
                'min:3',
                'max:64',
                'regex:/^[A-Za-z0-9_\-]+$/',
                Rule::unique('users', 'wechat_id'),
            ],

            'ic_no' => [
                'nullable',
                'string',
                'min:3',
                'max:64',
                'regex:/^[A-Za-z0-9_\-]+$/',
                Rule::unique('users', 'ic_no'),
            ],

            'age_attestation' => ['required', 'accepted'],
            'terms' => ['required', 'accepted'],
            'privacy_notice' => ['required', 'accepted'],
            'terms_version' => ['required', 'string', 'min:1', 'max:64'],
            'terms_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i'],
            'privacy_version' => ['required', 'string', 'min:1', 'max:64'],
            'privacy_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i'],
            ...app(CaptchaService::class)->validationRules('register'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $minAgeYears = min(255, max(18, (int) config('legal.minimum_age', 18)));

        return [
            'captcha_answer' => __('public_pages.captcha_label'),
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
            'wechat_id' => __('public_pages.register_wechat_id'),
            'ic_no' => __('public_pages.register_ic_no'),
            'age_attestation' => __('public_pages.register_age_attestation', ['age' => $minAgeYears]),
            'terms' => __('public_pages.register_terms'),
            'privacy_notice' => __('public_pages.register_privacy_notice'),
            'terms_version' => __('public_pages.register_terms'),
            'terms_hash' => __('public_pages.register_terms'),
            'privacy_version' => __('public_pages.register_privacy_notice'),
            'privacy_hash' => __('public_pages.register_privacy_notice'),
        ];
    }
}
