<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Rules\AccountIdentifierRule;
use App\Services\Auth\CaptchaService;
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
        return [
            'identifier' => ['required', 'string', 'max:255', new AccountIdentifierRule],
            ...app(CaptchaService::class)->validationRules('password_reset'),
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
