<?php

declare(strict_types=1);

namespace App\Http\Requests\Wallet;

use App\Enums\Currency;
use App\Enums\WalletConversionDirection;
use App\DTOs\Wallet\ConversionRequestData;
use App\Rules\ConversionAmountRule;
use App\Rules\ConversionDirectionRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A member asking what a conversion would cost.
 *
 * A quote moves no money, which is why this request carries no idempotency key
 * and is allowed to be made repeatedly: asking the price twice is not a
 * financial event. What it must carry is everything the price depends on —
 * direction, amount and currency — because a quote that was priced against
 * different inputs than the execution is the whole reason quote references
 * exist.
 *
 * WHY `user_id`, `wallet_id` AND `channel` ARE PROHIBITED
 * Every one of them is derived: the member from the session or token, the
 * wallets from the member and the direction, the channel from the route the
 * request arrived on. Accepting any of them from the client would let a caller
 * quote against another member's wallet, or claim an operator channel to obtain
 * operator pricing. Prohibiting the fields means an attempt to set them is a
 * validation error rather than something that has to be remembered and ignored
 * deeper in the stack.
 */
class ConversionQuoteRequest extends FormRequest
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
        $currency = $this->currency();

        return [
            'direction' => ['required', 'string', new ConversionDirectionRule],
            'amount' => ['required', 'string', new ConversionAmountRule($currency)],
            'currency' => ['nullable', 'string', Rule::enum(Currency::class)],

            // Derived server-side, never accepted from the wire.
            'user_id' => ['prohibited'],
            'wallet_id' => ['prohibited'],
            'channel' => ['prohibited'],
            'status' => ['prohibited'],
            'fee_amount' => ['prohibited'],
            'rate' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'direction.required' => 'Choose whether you are moving funds into or out of your Win wallet.',
            'amount.required' => 'Enter the amount you want to convert.',
            'currency.prohibited' => 'The conversion currency is set by the platform and cannot be chosen per request.',
        ];
    }

    public function currency(): Currency
    {
        $requested = $this->input('currency');

        if (is_string($requested)) {
            $enum = Currency::tryFrom(strtoupper(trim($requested)));

            if ($enum !== null) {
                return $enum;
            }
        }

        // The platform's configured conversion currency, which is where the
        // member's wallets live; the primary currency is only the fallback when
        // the configuration is unreadable.
        return Currency::tryFrom((string) config('conversions.currency', '')) ?? Currency::primary();
    }

    public function direction(): WalletConversionDirection
    {
        return WalletConversionDirection::tryFrom((string) $this->validated('direction'))
            ?? WalletConversionDirection::CashToWin;
    }

    /**
     * Build the engine's request DTO from validated input only.
     *
     * Building it here rather than in the controller keeps the "what arrives on
     * the wire" and "what the engine is asked to do" translation in one place,
     * and guarantees the controller cannot accidentally pass an unvalidated
     * field through.
     */
    public function toEngineRequest(int $userId, \App\Enums\WalletConversionChannel $channel): ConversionRequestData
    {
        return ConversionRequestData::fromArray([
            'direction' => (string) $this->validated('direction'),
            'amount' => (string) $this->validated('amount'),
            'currency' => $this->currency()->value,
            'channel' => $channel->value,
            'user_id' => $userId,
        ]);
    }
}
