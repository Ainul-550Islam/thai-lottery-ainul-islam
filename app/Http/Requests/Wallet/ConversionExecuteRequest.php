<?php

declare(strict_types=1);

namespace App\Http\Requests\Wallet;

use App\DTOs\Wallet\ConversionAuditContextData;
use App\DTOs\Wallet\ConversionRequestData;
use App\Enums\Currency;
use App\Enums\WalletConversionChannel;
use App\Rules\ConversionAmountRule;
use App\Rules\ConversionDirectionRule;
use App\Http\Responses\ApiResponse;
use App\Support\Wallet\ConversionCurrency;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * A member committing to a conversion.
 *
 * THE THREE THINGS THIS REQUEST GUARANTEES BEFORE THE CONTROLLER RUNS
 *
 *   1. An idempotency key is present and inside the configured length bounds.
 *      The middleware in front of the route has already refused a missing key,
 *      but the rule is repeated here because this request is also reached from
 *      console-driven tests and from the web form, where middleware order is not
 *      a guarantee. Two cheap checks that read the same configuration value are
 *      worth more than one that depends on the pipeline being arranged exactly
 *      as expected.
 *
 *   2. A quote reference is present whenever the configuration requires quotes.
 *      An execution without a quote would be priced at the moment of execution
 *      against a rate the member never saw. Whether a quote is required is a
 *      configuration decision, so the rule reads it rather than hard-coding the
 *      answer.
 *
 *   3. The amount is inside the envelope and the direction is open. These are the
 *      same checks the quote endpoint makes; repeating them here means a member
 *      whose quote expired and who raised the amount does not receive a
 *      confusing engine refusal that mentions a quote rather than the amount.
 *
 * WHY THE QUOTE REFERENCE IS NOT VALIDATED AGAINST THE DATABASE HERE
 * Its existence, expiry, direction, amount and rule version are all checked by
 * the quote service inside the money transaction, under the wallet locks. A
 * request-level existence check would be a second, weaker copy of that logic
 * that could pass while the locked check fails — and the copy that matters is
 * the one holding the lock.
 */
class ConversionExecuteRequest extends FormRequest
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
        $minimum = (int) config('conversions.idempotency.min_key_length', 16);
        $maximum = (int) config('conversions.idempotency.max_key_length', 128);
        $quoteRequired = (bool) config('conversions.quotes.require_for_execution', true);

        return [
            'direction' => ['required', 'string', new ConversionDirectionRule],
            'amount' => ['required', 'string', new ConversionAmountRule($this->currency())],
            'currency' => ['nullable', 'string', Rule::enum(Currency::class)],
            'idempotency_key' => ['required', 'string', 'min:'.$minimum, 'max:'.$maximum],

            'quote_reference' => [$quoteRequired ? 'required' : 'nullable', 'string', 'max:64'],

            // Derived server-side.
            'user_id' => ['prohibited'],
            'wallet_id' => ['prohibited'],
            'channel' => ['prohibited'],
            'fee_amount' => ['prohibited'],
            'amount_out' => ['prohibited'],
            'status' => ['prohibited'],
            'actor_user_id' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $minimum = (int) config('conversions.idempotency.min_key_length', 16);

        return [
            'idempotency_key.required' => 'This request moves money and must carry an idempotency key so a retry cannot convert twice.',
            'idempotency_key.min' => "The idempotency key must be at least {$minimum} characters.",
            'quote_reference.required' => 'Request a quote first, then execute against it — the price you were shown must be the price charged.',
        ];
    }

    public function currency(): Currency
    {
        return ConversionCurrency::forRequest($this);
    }

    public function toEngineRequest(int $userId, WalletConversionChannel $channel): ConversionRequestData
    {
        return ConversionRequestData::fromArray([
            'direction' => (string) $this->validated('direction'),
            'amount' => (string) $this->validated('amount'),
            'currency' => $this->currency()->value,
            'channel' => $channel->value,
            'user_id' => $userId,
            'idempotency_key' => (string) $this->validated('idempotency_key'),
            'quote_reference' => $this->validated('quote_reference'),
            'audit_context' => ConversionAuditContextData::fromRequest($this, $channel)->toArray(),
        ]);
    }

    /**
     * JSON callers get the platform error envelope with a stable code: a missing
     * key is `idempotency_key_required`, a malformed key is
     * `idempotency_key_invalid`, and anything else is `validation_failed`. Web
     * callers keep the framework's redirect with its errors.
     */
    protected function failedValidation(Validator $validator): void
    {
        $keyFailures = array_keys($validator->failed()['idempotency_key'] ?? []);

        // A missing or malformed key on a browser form is a tampered request, not a
        // typo the member can fix in place, so it is answered with a 422 status
        // rather than a redirect that would look like a successful form round trip.
        if (! $this->expectsJson()) {
            if ($keyFailures !== []) {
                abort(422, (string) $validator->errors()->first('idempotency_key'));
            }

            parent::failedValidation($validator);
        }

        $code = match (true) {
            in_array('Required', $keyFailures, true) => 'idempotency_key_required',
            $keyFailures !== [] => 'idempotency_key_invalid',
            default => 'validation_failed',
        };

        throw new HttpResponseException(ApiResponse::error(
            code: $code,
            message: $validator->errors()->first() ?: 'The conversion request is invalid.',
            status: 422,
            details: ['errors' => $validator->errors()->toArray()],
        ));
    }
}
