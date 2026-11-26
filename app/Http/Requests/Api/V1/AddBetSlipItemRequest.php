<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /me/bet-slip/items — input shape only.
 *
 * WHAT THIS CLASS DOES NOT DO
 * It does not decide whether a game exists, whether a number is well formed or
 * whether a stake is affordable. Those are the service's rules, expressed once, and
 * a form request that duplicated them would be the second answer to "may this be
 * added" — the answer that drifts.
 *
 * What it DOES enforce is SHAPE: present, string, bounded. `stake` is a string
 * rule on purpose (numeric would coerce 10.55 through a float on some drivers), and
 * `number` is a string for the same reason '007' must never become 7.
 *
 * NO member_id. The member is the authenticated user; a body field claiming one is
 * simply not read, and `prohibited` makes that explicit rather than silent.
 */
final class AddBetSlipItemRequest extends FormRequest
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
            'game_code' => ['required', 'string', 'max:40'],
            'bet_type' => ['required', 'string', 'max:24'],
            'number' => ['required', 'string', 'max:12'],
            'stake' => ['required', 'string', 'max:24'],
            'funding_source' => ['nullable', 'string', 'in:wallet'],
            'draw_id' => ['nullable', 'integer', 'min:1'],
            'free_bet_voucher_reference' => ['nullable', 'string', 'max:64'],

            // Values a client may never supply: totals are computed server-side.
            'member_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'potential_payout' => ['prohibited'],
            'payout_multiplier' => ['prohibited'],
            'status' => ['prohibited'],
            'total_stake' => ['prohibited'],
        ];
    }
}
