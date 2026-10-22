<?php

declare(strict_types=1);

namespace App\Http\Requests\Transfer;

use App\Enums\TransferDirection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a balance-transfer submission.
 *
 * WHAT THIS LAYER OWNS
 * Shape only: is `direction` one of the two lanes, is `amount` present and a
 * plain decimal string, is the idempotency key a sane length. Answering those
 * here means the service never has to parse an arbitrary string, and a malformed
 * request gets a standard 422 validation envelope rather than a domain refusal.
 *
 * WHAT THIS LAYER DELIBERATELY DOES NOT CHECK
 * No balance, no limits, no fee, no wallet status. Every one of those needs a row
 * lock to answer truthfully, and checking them here would produce a "valid"
 * verdict that is stale by the time the service runs. BalanceTransferService
 * re-checks all of them inside the locked transaction — that is the authoritative
 * gate, and this request is only the shape filter in front of it.
 *
 * The amount pattern is intentionally duplicated in the service. A FormRequest can
 * be bypassed (console, queued job, a future controller), and money validation
 * must not depend on which entry point was used.
 */
final class StoreBalanceTransferRequest extends FormRequest
{
    /**
     * Plain decimal, no sign, no exponent, no separators, at most 2dp.
     *
     * The 13-digit integer bound keeps the value far inside DECIMAL(20,2) and far
     * outside anything a real wallet could hold, so overflow is not reachable.
     */
    private const AMOUNT_PATTERN = '/^\d{1,13}(?:\.\d{1,2})?$/';

    public function authorize(): bool
    {
        // Authentication is enforced by the route middleware. Ownership is not a
        // question here: a player can only ever transfer between their own two
        // pockets, and the service resolves both wallets from the authenticated
        // user rather than from anything in this payload.
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxKeyLength = (int) config('transfer.idempotency.max_key_length', 128);

        return [
            'direction' => [
                'required',
                'string',
                Rule::in(TransferDirection::values()),
            ],

            // Validated as a string, never cast to float: `numeric` would accept
            // "1e5" and " 100 ", and a float round-trip is how cent errors start.
            'amount' => [
                'required',
                'string',
                'regex:'.self::AMOUNT_PATTERN,
            ],

            // Optional. When absent the service still guards against double
            // submits with the per-direction cooldown.
            'idempotency_key' => [
                'nullable',
                'string',
                'max:'.$maxKeyLength,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'direction.required' => __('transfer.validation.direction_required'),
            'direction.in' => __('transfer.validation.direction_invalid', [
                'allowed' => implode(', ', TransferDirection::values()),
            ]),
            'amount.required' => __('transfer.validation.amount_required'),
            'amount.regex' => __('transfer.validation.amount_malformed'),
            'idempotency_key.max' => __('transfer.validation.idempotency_key_too_long'),
        ];
    }

    /**
     * The direction, resolved. Safe to call after validation passes.
     *
     * Falls back to null rather than throwing if the value is somehow not a lane,
     * so a controller can render a normal validation error instead of a 500.
     */
    public function direction(): ?TransferDirection
    {
        return TransferDirection::tryFromInput($this->input('direction'));
    }

    /**
     * The amount exactly as submitted, trimmed.
     *
     * The service normalises to scale 2; this returns the raw string so the
     * service stays the single place that decides what a valid amount means.
     */
    public function amount(): string
    {
        return trim((string) $this->input('amount', ''));
    }

    public function idempotencyKey(): ?string
    {
        $key = $this->input('idempotency_key');

        if (! is_string($key)) {
            // Also accept the conventional header, so an API client that sets
            // Idempotency-Key (and no body field) is honoured.
            $header = $this->header((string) config('transfer.idempotency.header', 'Idempotency-Key'));

            return is_string($header) && trim($header) !== '' ? trim($header) : null;
        }

        return trim($key) === '' ? null : trim($key);
    }

    /**
     * Normalise the direction to lower case so "CASH_TO_WIN" validates.
     */
    protected function prepareForValidation(): void
    {
        $direction = $this->input('direction');

        if (is_string($direction)) {
            $this->merge(['direction' => strtolower(trim($direction))]);
        }

        $amount = $this->input('amount');

        if (is_string($amount)) {
            $this->merge(['amount' => trim($amount)]);
        } elseif (is_int($amount) || is_float($amount)) {
            // A JSON client may send a number. Render it without exponent
            // notation and let the regex decide, rather than silently accepting
            // a float the player never typed.
            $this->merge(['amount' => is_float($amount) ? rtrim(rtrim(sprintf('%.2F', $amount), '0'), '.') : (string) $amount]);
        }
    }
}
