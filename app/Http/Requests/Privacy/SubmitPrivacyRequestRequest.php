<?php

declare(strict_types=1);

namespace App\Http\Requests\Privacy;

use App\Enums\PrivacyRequestType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The public privacy-request intake.
 *
 * TWO RULES SHAPE THIS CLASS.
 *
 * 1. THE FORM IS TEXT, NOT FILES. Every spelling of "attachment" listed in
 *    config('privacy_requests.rejected_upload_fields') is refused explicitly,
 *    even when it arrives empty. A public endpoint that quietly ignored an
 *    uploaded passport would be accepting it; this one says no.
 *
 * 2. AN ACCOUNT HOLDER IS NOT ASKED FOR AN ADDRESS. The signed-in route
 *    identifies the requester by session; requiring them to retype an address
 *    would either duplicate the account's (noise) or contradict it (a request
 *    about somebody else's data). The service reads the account address and
 *    ignores whatever the form carried.
 *
 * 3. VALIDATION MUST NOT BECOME AN ORACLE. The rules are shape rules only -
 *    syntactically valid address, a type from the configured list, a bounded
 *    description. Nothing here asks the database whether an account exists, so
 *    a 422 never means "no such user".
 *
 * Control characters are stripped from the description before the length rule
 * runs, so a payload padded with NULs cannot pass the bound and then be stored.
 */
final class SubmitPrivacyRequestRequest extends FormRequest
{
    public const HONEYPOT_FIELD = 'website';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $details = $this->input('details');

        if (is_string($details)) {
            $details = str_replace(["\r\n", "\r"], "\n", $details);
            $details = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $details) ?? '';
        }

        $type = $this->input('type');

        $this->merge([
            'email' => is_string($this->input('email'))
                ? mb_strtolower(trim((string) $this->input('email')))
                : $this->input('email'),
            'type' => is_string($type) ? strtolower(trim($type)) : $type,
            'details' => is_string($details) ? trim($details) : $details,
            'locale' => is_string($this->input('locale'))
                ? strtolower(trim((string) $this->input('locale')))
                : $this->input('locale'),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $accepted = PrivacyRequestType::acceptedValues();
        $locales = array_values(array_filter(
            array_map('strval', (array) config('privacy_requests.locales', ['en'])),
            static fn (string $locale): bool => $locale !== '',
        ));

        $email = $this->user() !== null
            ? ['nullable', 'string', 'email:rfc', 'max:190']
            : ['required', 'string', 'email:rfc', 'max:190'];

        return [
            'email' => $email,
            'type' => ['required', 'string', Rule::in($accepted)],
            'details' => ['nullable', 'string', 'max:'.max(50, (int) config('privacy_requests.details_max_length', 2000))],
            'locale' => ['nullable', 'string', Rule::in($locales === [] ? ['en'] : $locales)],
            self::HONEYPOT_FIELD => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * A refusal for an attachment, wherever it was smuggled.
     *
     * Attached files are not "ignored": the request is refused, because a
     * visitor who has already sent a scan must be told it was not accepted
     * rather than believe it was received and stored.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ((array) config('privacy_requests.rejected_upload_fields', []) as $field) {
                $field = is_string($field) ? trim($field) : '';

                if ($field !== '' && $this->exists($field)) {
                    $validator->errors()->add(
                        'details',
                        (string) trans('privacy_requests.intake.attachments_not_accepted'),
                    );

                    return;
                }
            }

            if ($this->hasFile('details') || $this->files->count() > 0) {
                $validator->errors()->add(
                    'details',
                    (string) trans('privacy_requests.intake.attachments_not_accepted'),
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => (string) trans('privacy_requests.intake.email_invalid'),
            'email.email' => (string) trans('privacy_requests.intake.email_invalid'),
            'email.string' => (string) trans('privacy_requests.intake.email_invalid'),
            'email.max' => (string) trans('privacy_requests.intake.email_invalid'),
            'type.required' => (string) trans('privacy_requests.intake.type_unsupported'),
            'type.in' => (string) trans('privacy_requests.intake.type_unsupported'),
            'details.string' => (string) trans('privacy_requests.intake.invalid'),
            'details.max' => (string) trans('privacy_requests.intake.details_too_long'),
            'locale.in' => (string) trans('privacy_requests.intake.invalid'),
        ];
    }

    public function honeypotTripped(): bool
    {
        $value = $this->input(self::HONEYPOT_FIELD);

        return is_string($value) && trim($value) !== '';
    }

    public function requestType(): PrivacyRequestType
    {
        return PrivacyRequestType::from(strtolower(trim((string) $this->input('type'))));
    }

    public function localeOr(string $fallback): string
    {
        $locale = $this->input('locale');

        return is_string($locale) && in_array($locale, ['en', 'th', 'bn'], true) ? $locale : $fallback;
    }

    public function details(): ?string
    {
        $details = $this->input('details');

        if (! is_string($details)) {
            return null;
        }

        $details = trim($details);

        return $details === '' ? null : $details;
    }
}
