<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates operator input for creating/updating a canned response.
 *
 * ============================================================================
 * WHY THIS SURFACE GETS A FORMREQUEST AND THE CHAT SEND PATH DOES NOT
 * ============================================================================
 * The visitor/operator MESSAGE path refuses through ChatException, because
 * the widget and the console both switch on those machine codes. Canned
 * response administration is different: it is a staff CRUD form, its
 * failures are field errors shown next to inputs, and Laravel's standard
 * validation envelope is exactly what the admin blade/error bag expects.
 * Two surfaces, two honest contracts — not one contract forced on both.
 *
 * ============================================================================
 * RULES MIRROR THE SCHEMA AND THE SERVICE
 * ============================================================================
 * Nothing here invents a constraint: key/title lengths match the columns,
 * the placeholder pattern matches ChatCannedResponseService::adminAttributes,
 * and locale matches the lang directories that actually exist. A value that
 * passes here still passes through the service's sanitiser — defence twice,
 * by design, because the service is reachable from seeders and console
 * commands that never see this request.
 */
final class StoreCannedResponseRequest extends FormRequest
{
    /**
     * Only operators with the manage_canned permission reach this request;
     * the route middleware already enforced auth + admin access, so this is a
     * second lock rather than the first.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        try {
            return $user->hasPermissionTo((string) config('chat.operator.permissions.manage_canned'));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->route('cannedResponse') === null;

        return [
            'key' => [
                $creating ? 'required' : 'prohibited',
                'string',
                'min:2',
                'max:64',
                'regex:/^[a-z0-9_-]+$/',
                $creating ? Rule::unique('chat_canned_responses', 'key') : null,
            ],
            'title' => ['required', 'string', 'min:1', 'max:120'],
            'body' => ['required', 'string', 'min:1', 'max:5000'],
            'category' => ['nullable', 'string', 'max:48'],
            'placeholders' => ['sometimes', 'array', 'max:10'],
            'placeholders.*' => ['string', 'regex:/^[a-z][a-z0-9_]{0,31}$/'],
            'locale' => ['sometimes', 'string', Rule::in(['en', 'th'])],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:32000'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'key.regex' => 'Keys may only contain lowercase letters, numbers, dashes and underscores.',
            'placeholders.*.regex' => 'Placeholders must start with a letter and use only lowercase letters, numbers and underscores.',
        ];
    }

    /**
     * The validated payload, normalised exactly as the service expects.
     *
     * @return array<string, mixed>
     */
    public function cannedAttributes(): array
    {
        $attributes = [
            'title' => (string) $this->validated('title'),
            'body' => (string) $this->validated('body'),
            'category' => $this->validated('category'),
        ];

        if ($this->validated('key') !== null) {
            $attributes['key'] = (string) $this->validated('key');
        }

        if ($this->has('placeholders')) {
            $attributes['placeholders'] = $this->validated('placeholders') ?? [];
        }

        if ($this->has('locale')) {
            $attributes['locale'] = (string) $this->validated('locale');
        }

        if ($this->has('sort_order')) {
            $attributes['sort_order'] = (int) $this->validated('sort_order');
        }

        if ($this->has('enabled')) {
            $attributes['enabled'] = (bool) $this->validated('enabled');
        }

        return $attributes;
    }
}
