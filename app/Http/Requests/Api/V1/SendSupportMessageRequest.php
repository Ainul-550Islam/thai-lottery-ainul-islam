<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /me/support/conversations/{id}/messages.
 *
 * `client_token` is the idempotency handle the chat stack already understands: two
 * taps on send with the same token produce one message. The length cap is
 * config-driven so the operator can change it without a deployment, and it is the
 * same value the service enforces.
 */
final class SendSupportMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:1', 'max:'.(int) config('player_experience.support.max_message_length', 4000)],
            'client_token' => ['nullable', 'string', 'max:64'],
        ];
    }
}
