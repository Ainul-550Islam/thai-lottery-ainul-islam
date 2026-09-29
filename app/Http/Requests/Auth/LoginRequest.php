<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Rules\AccountIdentifierRule;
use Illuminate\Foundation\Http\FormRequest;

/*
 * PROMPT 3 — strict member login validation.
 *
 * Collects the credential fields, the CAPTCHA pair and the remember
 * flag. NEVER accepts a user-selected account status, role or any
 * authorization-relevant key: the rules whitelist is the boundary,
 * and the login service re-derives everything server-side.
 */
final class LoginRequest extends FormRequest
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
        $captchaEnabled = app(\App\Services\Auth\CaptchaService::class)->isEnabled()
            && (bool) config('auth_security.captcha.login', true);

        return [
            'login' => ['required', 'string', 'max:255', new AccountIdentifierRule()],
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['nullable', 'boolean'],
            'captcha_token' => [$captchaEnabled ? 'required' : 'nullable', 'string', 'max:128'],
            'captcha_answer' => [$captchaEnabled ? 'required' : 'nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'login' => __('public_pages.login_identifier'),
            'password' => __('public_pages.login_password'),
            'captcha_answer' => __('public_pages.captcha_label'),
        ];
    }
}
