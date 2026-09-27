<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Server-side validation for a public contact submission (PROMPT 10).
 *
 * THE CLIENT IS NOT CONSULTED. maxlength, required and type="email" in the
 * markup are conveniences for a person using the form. They are absent from
 * every request that did not come from the form, which is exactly the class of
 * request this file exists for. The bounds below are the control.
 *
 * NO CR OR LF IN HEADER FIELDS. Name, email and subject end up in mail
 * headers. A newline in any of them is how an injected Bcc: or a rewritten
 * Content-Type gets in, and no legitimate name or subject contains one, so the
 * rule refuses rather than strips - a request carrying one is not a typo.
 *
 * THE BODY KEEPS ITS NEWLINES. A support message has paragraphs. Only control
 * characters with no meaning in prose are rejected.
 */
class ContactMessageRequest extends FormRequest
{
    /**
     * Public form: anyone may submit.
     *
     * Abuse control is the limiter and the honeypot, not authorisation.
     * Requiring a login here would mean the people most likely to need support
     * - those who cannot sign in - are the ones who cannot ask for it.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $limits = (array) config('contact.limits', []);

        $max = static fn (string $key, int $fallback): int => is_int($limits[$key] ?? null) && $limits[$key] > 0
            ? $limits[$key]
            : $fallback;

        // No CR, LF, or other C0 control characters. Applied to the three
        // fields that become headers.
        $noControl = 'regex:/^[^\x00-\x08\x0A-\x1F\x7F]+$/u';

        return [
            'name' => ['required', 'string', 'min:2', 'max:'.$max('name', 120), $noControl],
            'email' => ['required', 'string', 'email:rfc', 'max:'.$max('email', 190), $noControl],
            'subject' => ['required', 'string', 'min:3', 'max:'.$max('subject', 160), $noControl],

            // Newlines allowed; everything else in the C0 range is not.
            'message' => [
                'required',
                'string',
                'min:10',
                'max:'.$max('message', 4000),
                'regex:/^[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+$/u',
            ],

            // The honeypot. A human never sees the field, so anything in it
            // came from something walking the form. Validated as "must be
            // empty" rather than rejected outright, so the response is
            // indistinguishable from success and a bot learns nothing.
            $this->honeypotField() => ['nullable', 'string', 'max:255'],

            'locale' => ['nullable', 'string', Rule::in(['en', 'th'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => trans('contact.validation_no_control_characters'),
            'email.regex' => trans('contact.validation_no_control_characters'),
            'subject.regex' => trans('contact.validation_no_control_characters'),
            'message.regex' => trans('contact.validation_no_control_characters'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => trans('contact.name'),
            'email' => trans('contact.email'),
            'subject' => trans('contact.subject'),
            'message' => trans('contact.message'),
        ];
    }

    /**
     * The sanitised payload the service consumes.
     *
     * @return array{name: string, email: string, subject: string, message: string, website: string|null}
     */
    public function payload(): array
    {
        $field = $this->honeypotField();

        return [
            'name' => (string) $this->validated('name'),
            'email' => (string) $this->validated('email'),
            'subject' => (string) $this->validated('subject'),
            'message' => (string) $this->validated('message'),
            $field => $this->input($field) === null ? null : (string) $this->input($field),
        ];
    }

    private function honeypotField(): string
    {
        $field = config('contact.anti_spam.honeypot_field');

        return is_string($field) && $field !== '' ? $field : 'website';
    }
}
