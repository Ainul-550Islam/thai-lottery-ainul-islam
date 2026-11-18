<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /me/support/conversations.
 *
 * A subject is required because a queue with untitled conversations is a queue
 * nobody can triage. The message is optional: a member may open the conversation
 * and type in the panel the screen opens next, and forcing an empty first message
 * to exist would put a meaningless row in the transcript.
 */
final class OpenSupportConversationRequest extends FormRequest
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
            'subject' => ['required', 'string', 'min:3', 'max:190'],
            'message' => ['nullable', 'string', 'max:'.(int) config('player_experience.support.max_message_length', 4000)],
            'member_id' => ['prohibited'],
        ];
    }
}
