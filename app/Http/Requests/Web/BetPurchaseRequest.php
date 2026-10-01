<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Web Bulk Bet Placement Request.
 *
 * Validates selected lottery items, stake bounds, and client idempotency tokens.
 */
class BetPurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'draw_reference' => ['required', 'string', 'max:128'],
            'client_key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.market' => ['required', 'string', 'max:32'],
            'items.*.number' => ['required', 'string', 'min:1', 'max:6', 'regex:/^[0-9]+$/'],
            'items.*.stake' => ['required', 'string', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'draw_reference.required' => (string) trans('player.draw_selection_invalid'),
            'client_key.required' => 'Client idempotency token is required.',
            'items.required' => 'Your bet slip contains no selections.',
            'items.max' => 'A maximum of 50 selections are allowed per slip.',
            'items.*.number.regex' => 'Lottery selections must contain valid numeric digits only.',
        ];
    }
}
