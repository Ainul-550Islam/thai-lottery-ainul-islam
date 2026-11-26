<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /me/support/conversations/{id}/rating.
 *
 * The range comes from config so the product can move from five stars without a
 * schema change. Whether the conversation may be rated at all (closed? already
 * rated?) is a FACT the service reads from the row, not a shape this validator can
 * see.
 */
final class RateSupportConversationRequest extends FormRequest
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
            'rating' => [
                'required',
                'integer',
                'min:'.(int) config('player_experience.support.rating_min', 1),
                'max:'.(int) config('player_experience.support.rating_max', 5),
            ],
            'comment' => ['nullable', 'string', 'max:500'],
        ];
    }
}
