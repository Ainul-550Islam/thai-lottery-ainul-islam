<?php

declare(strict_types=1);

namespace App\Http\Requests\Privacy;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Public ticket-status input: a reference and the address it was sent from.
 *
 * WHY THE RULES ARE SO PLAIN. This endpoint must not reveal anything, so its
 * validation must not either: both fields are shape-checked only, and a failure
 * produces one sentence that is identical for every cause. No "reference not
 * found" message exists here because no code path may discover that fact.
 *
 * The reference is normalised (trimmed, upper-cased) because the platform issues
 * upper-case references and a visitor reading one from a screenshot may type it
 * in lower case; that is a convenience, not a lookup shortcut.
 */
final class LookupTicketStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'reference' => is_string($this->input('reference'))
                ? strtoupper(trim((string) $this->input('reference')))
                : $this->input('reference'),
            'email' => is_string($this->input('email'))
                ? mb_strtolower(trim((string) $this->input('email')))
                : $this->input('email'),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9-]{4,32}$/'],
            'email' => ['required', 'string', 'email:rfc', 'max:190'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reference.required' => (string) trans('support_tickets.lookup.invalid'),
            'reference.regex' => (string) trans('support_tickets.lookup.invalid'),
            'reference.string' => (string) trans('support_tickets.lookup.invalid'),
            'reference.max' => (string) trans('support_tickets.lookup.invalid'),
            'email.required' => (string) trans('support_tickets.lookup.invalid'),
            'email.email' => (string) trans('support_tickets.lookup.invalid'),
            'email.string' => (string) trans('support_tickets.lookup.invalid'),
            'email.max' => (string) trans('support_tickets.lookup.invalid'),
        ];
    }

    public function reference(): string
    {
        return (string) $this->input('reference');
    }

    public function email(): string
    {
        return (string) $this->input('email');
    }
}
