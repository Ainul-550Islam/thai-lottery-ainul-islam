<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /me/bet-slip/confirm — the idempotency key, and nothing else.
 *
 * The key may arrive as the documented `Idempotency-Key` header or as a body field;
 * the controller reads the header first. Both are accepted because both are in the
 * wild, but ONE is required: a confirm with no key is refused, since a retried
 * confirm without one is a second purchase.
 *
 * A caller cannot send items here. Confirming purchases what is in the slip at the
 * moment of the transaction — accepting an item list on the confirm request would
 * make the reviewed slip and the purchased slip two different things.
 */
final class ConfirmBetSlipRequest extends FormRequest
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
            'idempotency_key' => ['nullable', 'string', 'min:8', 'max:96', 'regex:/^[A-Za-z0-9:_\-]+$/'],

            // A confirm request carries no selections and no amounts.
            'items' => ['prohibited'],
            'total_stake' => ['prohibited'],
            'stake' => ['prohibited'],
        ];
    }

    public function idempotencyKey(): string
    {
        $header = $this->header('Idempotency-Key');

        if (is_string($header) && trim($header) !== '') {
            return trim($header);
        }

        return (string) $this->input('idempotency_key', '');
    }
}
