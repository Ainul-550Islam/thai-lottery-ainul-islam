<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Rules\AccountIdentifierRule;
use Illuminate\Foundation\Http\FormRequest;

/*
 * PROMPT 3 — password-recovery request validation.
 *
 * "Account No. or email" + CAPTCHA. The anti-enumeration response
 * behaviour is NOT decided here — the service always answers the
 * same generic way regardless of what this validation accepted.
 */
final class ForgotPasswordRequest extends FormRequest
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
            && (bool) config('auth_security.captcha.password_reset', true);

        return [
            'identifier' => ['required', 'string', 'max:255', new AccountIdentifierRule()],
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
            'identifier' => __('public_pages.forgot_identifier'),
            'captcha_answer' => __('public_pages.captcha_label'),
        ];
    }
}
