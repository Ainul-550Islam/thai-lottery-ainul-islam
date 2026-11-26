<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\BetFundingSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Structural validation for POST /api/v1/games/{code}/bets.
 *
 * WHAT THIS CLASS ADDS TO THE QUOTE REQUEST
 * Three things, and each corresponds to a real placement hazard:
 *
 *   idempotency_key  a placement must be replayable. Without a caller-supplied key a
 *                    retried request is indistinguishable from a second bet, and the
 *                    wallet would be debited twice. Required, bounded, and never derived
 *                    from request content here — that derivation belongs to the purchase
 *                    lane, which owns the replay contract.
 *   draw_id          optional. An omitted draw means "the open draw of this game", which
 *                    is what a client that quoted from /upcoming expects. An explicit id
 *                    is honoured only while that draw still accepts bets, so a stale
 *                    client is refused rather than silently re-pointed at a later draw it
 *                    never agreed to.
 *   funding_source   optional, defaulting to real money. A free-bet placement must also
 *                    name a voucher, which is checked by the service (the voucher's value
 *                    and usability come from the Batch-1 provider, not from a rule here).
 *
 * WHAT THIS CLASS DELIBERATELY DOES NOT VALIDATE
 * Whether the selection exists, whether the stake is within the game's bounds, whether the
 * game is sellable, and whether the liability cap allows the bet. Those are the digit-game
 * services' authority and they are all re-checked after this class accepts the request.
 *
 * THERE IS NO `status`, `payout`, `user_id` OR `market_key` FIELD
 * A client cannot declare its own payout, its own identity or its own settlement market.
 * `user_id` in particular is taken from the authenticated request only, so a crafted body
 * cannot place a bet on somebody else's account.
 */
final class PlaceGameBetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // THE SAME TWO SHAPES AS THE QUOTE, WITH THE SAME LIMIT.
            // One placement buys ONE auditable bet: it carries one idempotency key, one
            // reservation and one attribution row. A basket cannot be made atomic through
            // this path - Batch 1's own basket purchase is - so a list longer than one is
            // refused here rather than half-placed with a shared key, which would make a
            // partial failure indistinguishable from a retry.
            'bet_type' => ['required_without:selections', 'string', 'max:32'],
            'number' => ['required_without:selections', 'string', 'max:16', 'regex:/^\d+$/'],
            'stake' => ['required_without:selections', 'string', 'max:24', 'regex:/^\d{1,18}(\.\d{1,2})?$/'],
            'selections' => ['sometimes', 'array', 'min:1', 'max:1'],
            'selections.*.bet_type' => ['required_with:selections', 'string', 'max:32'],
            'selections.*.number' => ['required_with:selections', 'string', 'max:16', 'regex:/^\d+$/'],
            'selections.*.stake' => ['required_with:selections', 'string', 'max:24', 'regex:/^\d{1,18}(\.\d{1,2})?$/'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:128'],
            'draw_id' => ['nullable', 'integer', 'min:1'],
            'funding_source' => ['nullable', 'string', Rule::in(BetFundingSource::values())],
            'voucher_id' => ['nullable', 'string', 'max:64'],
            'free_bet_voucher_id' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'number.regex' => 'A selection must be sent as a digit string so leading zeros survive.',
            'stake.regex' => 'A stake must be sent as a decimal string with at most two decimal places.',
            'idempotency_key.required' => 'A placement must carry an idempotency key so a retry cannot buy twice.',
            'idempotency_key.min' => 'An idempotency key must be at least 8 characters so distinct requests cannot collide.',
            'selections.max' => 'One selection per placement: each digit bet is reserved, bought and audited on its own.',
            'selections.min' => 'A selections list must name at least one selection.',
        ];
    }

    /**
     * The single selection the request describes, whichever shape it used.
     *
     * @return array{bet_type: string, number: string, stake: string}
     */
    public function selectionPayload(): array
    {
        $selection = $this->input('selections');

        if (is_array($selection) && $selection !== []) {
            $first = (array) reset($selection);

            return [
                'bet_type' => (string) ($first['bet_type'] ?? ''),
                'number' => (string) ($first['number'] ?? ''),
                'stake' => (string) ($first['stake'] ?? ''),
            ];
        }

        return [
            'bet_type' => (string) $this->input('bet_type'),
            'number' => (string) $this->input('number'),
            'stake' => (string) $this->input('stake'),
        ];
    }

    /**
     * The voucher id, under either name.
     */
    public function voucherId(): ?string
    {
        $canonical = $this->input('voucher_id');

        if (is_string($canonical) && $canonical !== '') {
            return $canonical;
        }

        $alias = $this->input('free_bet_voucher_id');

        return is_string($alias) && $alias !== '' ? $alias : null;
    }

    /**
     * The draw the caller named, or 0 meaning "the game's open draw".
     */
    public function drawId(): int
    {
        $value = $this->input('draw_id');

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * The funding source, defaulting to real money.
     */
    public function fundingSource(): string
    {
        $value = $this->input('funding_source');

        return is_string($value) && $value !== '' ? $value : BetFundingSource::Real->value;
    }
}
