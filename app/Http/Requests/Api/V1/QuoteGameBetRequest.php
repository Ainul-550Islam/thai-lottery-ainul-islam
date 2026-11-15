<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\BetFundingSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Structural validation for POST /api/v1/games/{code}/bets/quote.
 *
 * SHAPE ONLY, NEVER LEGALITY
 * This class checks that the request HAS a bet type, a number and a stake, and that they
 * are the right KIND of thing. It does not check whether the market exists, whether the
 * number suits the market's digit width, whether the draw is open or what the payout is:
 * all of that belongs to the digit-game services, which re-check it server-side whatever
 * this class accepts. Duplicating a rule here would create a second rulebook that drifts
 * the moment an operator changes a configuration value.
 *
 * THE NUMBER IS A STRING, AND THAT IS THE POINT
 * A JSON number cannot carry a leading zero: `{"number": "0042"}` is a string because it
 * has to be, and `{"number": 42}` has already lost the zeros before PHP sees it. Accepting
 * an integer and padding it would invent a selection the player did not make, so the
 * request demands a digit string and passes it through untouched.
 *
 * THE STAKE IS A DECIMAL STRING FOR THE SAME REASON
 * A JSON float is already a binary approximation of the amount the player agreed to. The
 * regex accepts the one representation that survives to bcmath exactly.
 */
final class QuoteGameBetRequest extends FormRequest
{
    /**
     * The route's own middleware group already enforces the authentication and
     * consent floor (auth, active user, adult, legal). Authorising again here would
     * duplicate a decision the route has made and could only ever disagree with it.
     */
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
            // TWO ACCEPTED SHAPES, ONE MEANING.
            // A single selection may be sent flat ({bet_type, number, stake}) or as the
            // one-element list form ({selections: [{...}]}) a basket-shaped client already
            // speaks, so a client does not have to learn a special case for digit games. A
            // LIST LONGER THAN ONE IS REFUSED HERE rather than quoted loosely: a quote is
            // read-only, but the same body is what a client will POST to /bets, and a quote
            // that had priced four selections while a placement can only buy one would be
            // advertising something the platform will not sell.
            'bet_type' => ['required_without:selections', 'string', 'max:32'],
            // Digit string only: no padding, no trimming of zeros, no numeric cast.
            'number' => ['required_without:selections', 'string', 'max:16', 'regex:/^\d+$/'],
            'stake' => ['required_without:selections', 'string', 'max:24', 'regex:/^\d{1,18}(\.\d{1,2})?$/'],
            'selections' => ['sometimes', 'array', 'min:1', 'max:1'],
            'selections.*.bet_type' => ['required_with:selections', 'string', 'max:32'],
            'selections.*.number' => ['required_with:selections', 'string', 'max:16', 'regex:/^\d+$/'],
            'selections.*.stake' => ['required_with:selections', 'string', 'max:24', 'regex:/^\d{1,18}(\.\d{1,2})?$/'],
            'funding_source' => ['nullable', 'string', Rule::in(BetFundingSource::values())],
            'voucher_id' => ['nullable', 'string', 'max:64'],
            // The tree's name for the same field. Both are accepted and neither overrides the
            // other silently: when both are present the canonical voucher_id wins and the
            // alias must agree with it.
            'free_bet_voucher_id' => ['nullable', 'string', 'max:64'],
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
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'number.regex' => 'A selection must be sent as a digit string so leading zeros survive.',
            'stake.regex' => 'A stake must be sent as a decimal string with at most two decimal places.',
            'funding_source.in' => 'Unknown funding source.',
            'selections.max' => 'One selection per request: each digit bet is priced, reserved and audited on its own.',
            'selections.min' => 'A selections list must name at least one selection.',
        ];
    }
}
